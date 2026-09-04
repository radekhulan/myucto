-- Rozšíření asistenta o návrhy analytických účtů a firemních předkontací.

ALTER TABLE accounting_setup_proposals
    MODIFY COLUMN proposal_type ENUM(
        'chart_account', 'expense_rule', 'posting_rule',
        'bank_rule', 'asset_candidate', 'data_quality'
    ) NOT NULL;
