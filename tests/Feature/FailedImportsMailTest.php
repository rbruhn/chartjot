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
