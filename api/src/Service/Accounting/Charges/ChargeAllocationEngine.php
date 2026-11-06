<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Charges;

final class ChargeAllocationEngine
{
    public const ROUNDING = 'aggregate_half_up_largest_remainder_subject_id_v1';

    public static function allocate(array $amount, array $subjects, string $mode): array
    {
        if (!in_array($mode, ['rate_per_basis', 'total_budget'], true)) throw new \InvalidArgumentException('An explicit allocation mode is required.');
        $amount = ExactFraction::normalize($amount, true);
        if ($mode === 'total_budget' && $amount['den'] !== '1') throw new \InvalidArgumentException('A budget requires whole cents.');
        $negative = str_starts_with($amount['num'], '-');
        $amount['num'] = ltrim($amount['num'], '-');
        $bases = []; $sum = ['num'=>'0','den'=>'1'];
        foreach ($subjects as $subject) {
            $id = $subject['id'] ?? null;
            if (!is_int($id) || $id <= 0 || isset($bases[$id]) || !is_array($subject['basis'] ?? null)) throw new \InvalidArgumentException('Each subject requires a unique positive identity and explicit basis.');
            $bases[$id] = ExactFraction::normalize($subject['basis']);
            $sum = ExactFraction::add($sum, $bases[$id]);
        }
        if ($bases === []) throw new \InvalidArgumentException('Select the subjects of the allocation.');
        ksort($bases, SORT_NUMERIC);
        if ($mode === 'total_budget' && $sum['num'] === '0' && $amount['num'] !== '0') throw new \InvalidArgumentException('A nonzero budget requires a nonzero basis.');
        $exactTotal = ['num'=>'0','den'=>'1']; $lines = []; $floorTotal = '0';
        foreach ($bases as $id=>$basis) {
            $weight = $mode === 'total_budget' && $sum['num'] !== '0'
                ? ExactFraction::multiply($basis, ['num'=>$sum['den'],'den'=>$sum['num']]) : $basis;
            $exact = ExactFraction::multiply($amount, $weight);
            $floor = bcdiv($exact['num'], $exact['den'], 0);
            $lines[] = ['subject_id'=>$id, 'basis'=>$basis, 'exact_cents'=>$exact, 'amount_cents'=>$floor,
                'residual_cent'=>false, 'remainder'=>['num'=>bcmod($exact['num'],$exact['den'],0),'den'=>$exact['den']]];
            $floorTotal = bcadd($floorTotal,$floor,0);
            $exactTotal = ExactFraction::add($exactTotal,$exact);
        }
        $total = ExactFraction::round($exactTotal);
        $remaining = (int) bcsub($total,$floorTotal,0);
        $order = array_keys($lines);
        usort($order, static function(int $a,int $b) use($lines): int {
            $x=$lines[$a]['remainder']; $y=$lines[$b]['remainder'];
            return -bccomp(bcmul($x['num'],$y['den'],0),bcmul($y['num'],$x['den'],0),0) ?: $lines[$a]['subject_id'] <=> $lines[$b]['subject_id'];
        });
        foreach (array_slice($order,0,$remaining) as $index) {
            $lines[$index]['amount_cents']=bcadd($lines[$index]['amount_cents'],'1',0);
            $lines[$index]['residual_cent']=true;
        }
        foreach ($lines as &$line) {
            unset($line['remainder']);
            if ($negative) {
                if ($line['amount_cents'] !== '0') $line['amount_cents']='-'.$line['amount_cents'];
                if ($line['exact_cents']['num'] !== '0') $line['exact_cents']['num']='-'.$line['exact_cents']['num'];
            }
        }
        unset($line);
        return ['basis_total'=>$sum, 'total_cents'=>$negative && $total !== '0' ? '-'.$total : $total, 'lines'=>$lines];
    }
}
