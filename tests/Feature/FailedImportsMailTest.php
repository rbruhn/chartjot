<?php

use App\Mail\FailedImportsMail;

test('failed imports email body does not leak trade data, only the CSV attachment does', function () {
    $failures = [
        [
            'account_name'    => 'Sim110',
            'source_trade_id' => 'csv_41cf44959b5f9ff1',
            'reason'          => "Account 'Sim110' not found. Create it on the Accounts page before importing.",
            'occurred_at'     => '2026-09-29 16:37:11',
        ],
    ];

    $mail = new FailedImportsMail('Ryan\'s Trade Journal', $failures);

    $html = $mail->render();

    expect($html)->not->toContain('csv_41cf44959b5f9ff1')
        ->and($html)->not->toContain("Account 'Sim110' not found")
        ->and($html)->not->toContain('Sim110')
        ->and($html)->toContain('1 trade could not be imported')
        ->and($html)->toContain('CSV of all failures is attached');

    // The data still goes out — just as the attachment, not the body.
    $attachments = $mail->attachments();
    expect($attachments)->toHaveCount(1)
        ->and($attachments[0]->as)->toBe('failed-imports.csv')
        ->and($attachments[0]->mime)->toBe('text/csv');
});

test('the MAE/MFE import failure email explains itself and still keeps row data out of the body', function () {
    $failures = [
        [
            'account_name'    => 'Sim101',
            'source_trade_id' => null,
            'reason'          => 'No matching trade: no imported trade on account Sim101 for NQ 12-26 spans 1/2/2026 11:00:00 AM → 1/2/2026 11:05:00 AM.',
            'occurred_at'     => '2026-09-30 13:08:00',
        ],
        [
            'account_name'    => 'Sim110',
            'source_trade_id' => 'csv_41cf44959b5f9ff1',
            'reason'          => "Trade found (account Sim110, MES 12-26) but this file's rows cover 2 of 3 contracts.",
            'occurred_at'     => '2026-09-30 13:08:00',
        ],
    ];

    $mail = new FailedImportsMail('Ryan\'s Trade Journal', $failures, FailedImportsMail::KIND_MAE_MFE);
    $html = $mail->render();

    expect($html)->not->toContain('Sim101')
        ->and($html)->not->toContain('Sim110')
        ->and($html)->not->toContain('NQ 12-26')
        ->and($html)->not->toContain('csv_41cf44959b5f9ff1')
        ->and($html)->not->toContain('No matching trade')
        // Says what actually happened, not the Executions import's "account name" explanation.
        ->and($html)->toContain('2 rows from your Trades export could not be matched')
        ->and($html)->not->toContain('did not match any account')
        ->and($html)->toContain('CSV of all failures is attached')
        ->and($mail->envelope()->subject)->toContain('MAE/MFE');

    expect($mail->attachments())->toHaveCount(1)
        ->and($mail->attachments()[0]->as)->toBe('failed-imports.csv');
});
