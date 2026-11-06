"""Own bridge/worker descendants before permitting the fixed command to start."""
import ctypes
import math
import os
from pathlib import Path
import signal
import subprocess
import sys
import time


class OwnedProcessTimeout(RuntimeError):
    def __init__(self):
        super().__init__('owned_process_timeout')


class OwnedProcessCancelled(RuntimeError):
    def __init__(self):
        super().__init__('owned_process_cancelled')


_POLL_SECONDS = 0.05


def _cancelled(cancel_event):
    if cancel_event is None:
        return False
    check = getattr(cancel_event, 'is_set', None)
    if not callable(check):
        raise ValueError('invalid_owned_process_cancel_event')
    return check()


def _raise_if_cancelled(cancel_event):
    if _cancelled(cancel_event):
        raise OwnedProcessCancelled()


if os.name == 'nt':
    from ctypes import wintypes as wt

    class _Limits(ctypes.Structure):
        _fields_ = [('user', ctypes.c_int64), ('kernel', ctypes.c_int64), ('flags', wt.DWORD),
                    ('minimum', ctypes.c_size_t), ('maximum', ctypes.c_size_t), ('count', wt.DWORD),
                    ('affinity', ctypes.c_size_t), ('priority', wt.DWORD), ('scheduling', wt.DWORD)]

    class _Io(ctypes.Structure):
        _fields_ = [(name, ctypes.c_uint64) for name in ('read', 'write', 'other', 'read_bytes', 'write_bytes', 'other_bytes')]

    class _Extended(ctypes.Structure):
        _fields_ = [('basic', _Limits), ('io', _Io), ('process_memory', ctypes.c_size_t),
                    ('job_memory', ctypes.c_size_t), ('peak_process', ctypes.c_size_t), ('peak_job', ctypes.c_size_t)]

    class _Accounting(ctypes.Structure):
        _fields_ = [(name, ctypes.c_int64) for name in ('user', 'kernel', 'period_user', 'period_kernel')] + [
            (name, wt.DWORD) for name in ('faults', 'total', 'active', 'terminated')]

    _api = ctypes.WinDLL('kernel32', use_last_error=True)
    for name, args, result in [
        ('CreateJobObjectW', [ctypes.c_void_p, wt.LPCWSTR], wt.HANDLE),
        ('SetInformationJobObject', [wt.HANDLE, ctypes.c_int, ctypes.c_void_p, wt.DWORD], wt.BOOL),
        ('AssignProcessToJobObject', [wt.HANDLE, wt.HANDLE], wt.BOOL),
        ('QueryInformationJobObject', [wt.HANDLE, ctypes.c_int, ctypes.c_void_p, wt.DWORD, ctypes.c_void_p], wt.BOOL),
        ('TerminateJobObject', [wt.HANDLE, wt.UINT], wt.BOOL),
        ('SetHandleInformation', [wt.HANDLE, wt.DWORD, wt.DWORD], wt.BOOL),
        ('CloseHandle', [wt.HANDLE], wt.BOOL),
    ]:
        getattr(_api, name).argtypes = args
        getattr(_api, name).restype = result

    class _WindowsJob:
        def __init__(self):
            self.handle = _api.CreateJobObjectW(None, None)
            if not self.handle:
                raise RuntimeError('owned_job_create_failed')
            if not _api.SetHandleInformation(self.handle, 0x00000001, 0):
                self.close()
                raise RuntimeError('owned_job_noinherit_failed')
            limits = _Extended()
            limits.basic.flags = 0x2000  # JOB_OBJECT_LIMIT_KILL_ON_JOB_CLOSE
            if not _api.SetInformationJobObject(self.handle, 9, ctypes.byref(limits), ctypes.sizeof(limits)):
                self.close()
                raise RuntimeError('owned_job_limit_failed')

        def assign(self, process):
            if not _api.AssignProcessToJobObject(self.handle, wt.HANDLE(int(process._handle))):
                raise RuntimeError('owned_job_assignment_failed')

        def active(self):
            value = _Accounting()
            if not _api.QueryInformationJobObject(self.handle, 1, ctypes.byref(value), ctypes.sizeof(value), None):
                raise RuntimeError('owned_job_query_failed')
            return value.active

        def terminate(self):
            if not _api.TerminateJobObject(self.handle, 2):
                raise RuntimeError('owned_job_termination_failed')

        def close(self):
            if self.handle:
                handle, self.handle = self.handle, None
                if not _api.CloseHandle(handle):
                    raise RuntimeError('owned_job_close_failed')


def run_owned(command, *, cwd, stdout, stderr, timeout, cancel_event=None):
    if not isinstance(timeout, (int, float)) or not math.isfinite(timeout) or timeout <= 0:
        raise ValueError('invalid_owned_process_timeout')
    _raise_if_cancelled(cancel_event)
    if os.name != 'nt':
        import select
        status_read, status_write = os.pipe()
        process = None
        deadline = time.monotonic() + timeout
        try:
            process = subprocess.Popen(
                [sys.executable, str(Path(__file__).resolve()), '--owned-posix-child', str(status_write), *command],
                cwd=cwd, stdout=stdout, stderr=stderr, stdin=subprocess.DEVNULL,
                start_new_session=True, pass_fds=(status_write,))
            os.close(status_write)
            status_write = -1
            while True:
                _raise_if_cancelled(cancel_event)
                remaining = deadline - time.monotonic()
                if remaining <= 0:
                    raise subprocess.TimeoutExpired(command, timeout)
                ready, _, _ = select.select([status_read], [], [], min(_POLL_SECONDS, remaining))
                if ready:
                    break
            _raise_if_cancelled(cancel_event)
            payload = os.read(status_read, 32)
            if not payload.endswith(b'\n') or len(payload) > 16:
                raise RuntimeError('owned_process_supervisor_failed')
            return int(payload[:-1])
        except BaseException as error:
            if isinstance(error, subprocess.TimeoutExpired):
                raise OwnedProcessTimeout() from None
            raise
        finally:
            if status_write >= 0:
                os.close(status_write)
            os.close(status_read)
            if process is not None:
                try:
                    os.killpg(process.pid, signal.SIGKILL)
                except ProcessLookupError:
                    pass
                process.wait(timeout=5)
    job = _WindowsJob()
    process = None
    assigned = False
    deadline = time.monotonic() + timeout
    try:
        process = subprocess.Popen([sys.executable, str(Path(__file__).resolve()), '--owned-child', *command],
                                   cwd=cwd, stdin=subprocess.PIPE, stdout=stdout, stderr=stderr,
                                   creationflags=subprocess.CREATE_NO_WINDOW)
        job.assign(process)
        assigned = True
        process.stdin.write(b'RUN\n')
        process.stdin.close()
        while True:
            _raise_if_cancelled(cancel_event)
            remaining = deadline - time.monotonic()
            if remaining <= 0:
                raise subprocess.TimeoutExpired(command, timeout)
            try:
                code = process.wait(timeout=min(_POLL_SECONDS, remaining))
                break
            except subprocess.TimeoutExpired:
                continue
        _raise_if_cancelled(cancel_event)
        deadline = time.monotonic() + 1
        while job.active() != 0 and time.monotonic() < deadline:
            _raise_if_cancelled(cancel_event)
            time.sleep(0.01)
        if job.active() != 0:
            raise RuntimeError('owned_process_orphan_descendants')
        return code
    except BaseException as error:
        if process is not None:
            if assigned:
                job.terminate()
            else:
                process.kill()  # No RUN grant: wrapper cannot have launched the command.
            process.wait(timeout=5)
        if isinstance(error, subprocess.TimeoutExpired):
            raise OwnedProcessTimeout() from None
        raise
    finally:
        if process is not None and process.stdin is not None and not process.stdin.closed:
            process.stdin.close()
        job.close()


def _child():
    if sys.argv[1:2] != ['--owned-child'] or len(sys.argv) < 3 or sys.stdin.buffer.readline() != b'RUN\n':
        return 2
    try:
        return subprocess.call(sys.argv[2:], stdin=subprocess.DEVNULL)
    except BaseException:
        return 2


def _posix_child():
    if os.name == 'nt' or sys.argv[1:2] != ['--owned-posix-child'] or len(sys.argv) < 4 or not sys.argv[2].isdigit():
        return 2
    status = int(sys.argv[2])
    try:
        code = subprocess.call(sys.argv[3:], stdin=subprocess.DEVNULL)
        os.write(status, f'{code}\n'.encode('ascii'))
        os.close(status)
        while True:
            signal.pause()
    except BaseException:
        try:
            os.killpg(os.getpgrp(), signal.SIGKILL)
        except ProcessLookupError:
            pass
        return 2


def _main():
    if sys.argv[1:2] == ['--owned-posix-child']:
        return _posix_child()
    return _child()


if __name__ == '__main__':
    raise SystemExit(_main())
