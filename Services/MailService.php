<?php
declare (strict_types = 1);

namespace Jamaat\Cron\Services;

use Jamaat\Cron\Infrastructure\Logger;
use PHPMailer\PHPMailer\Exception as MailerException;
use PHPMailer\PHPMailer\PHPMailer;

final class MailService
{
    /** @var array<string, string> */
    private array $config;
    private Logger $logger;

    /**
     * @param array<string, string> $env Loaded .env values (SMTP_* and MAIL_* keys)
     */
    public function __construct(array $env, Logger $logger)
    {
        $this->config = $env;
        $this->logger = $logger;
    }

    /**
     * Send one notification email listing every aamil change detected in a run.
     * Never throws: a mail failure must not fail the sync itself.
     *
     * @param array<int, array{jamaat_id: int, jamaat: string, jamiaat: string, old_its_id: string, new_its_id: string}> $changes
     */
    public function sendAamilChangeNotification(string $runId, array $changes): bool
    {
        if (empty($changes)) {
            return false;
        }

        if (($this->config['SEND_MAIL'] ?? '1') !== '1') {
            $this->logger->info('Aamil change email skipped: SEND_MAIL disabled');
            return false;
        }

        $recipients = $this->recipients();
        if (empty($recipients) || ($this->config['SMTP_HOST'] ?? '') === '') {
            $this->logger->error('Aamil change email skipped: SMTP_HOST or MAIL_TO not configured');
            return false;
        }

        try {
            $mail = new PHPMailer(true);

            // SMTP configuration
            $mail->isSMTP();
            $mail->Host       = $this->config['SMTP_HOST'];
            $mail->SMTPAuth   = true;
            $mail->Username   = $this->config['SMTP_USER'] ?? '';
            $mail->Password   = $this->config['SMTP_PASS'] ?? '';
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port       = (int) ($this->config['SMTP_PORT'] ?? 587);
            $mail->CharSet    = PHPMailer::CHARSET_UTF8;

            $mail->setFrom(
                $this->config['SMTP_FROM'] ?? $mail->Username,
                $this->config['SMTP_FROM_NAME'] ?? 'Jamaat Sync Cron'
            );
            foreach ($recipients as $address) {
                $mail->addAddress($address);
            }

            $mail->isHTML(true);
            $mail->Subject = sprintf('Aamil Saheb Changed - %d Jamaat(s) - %s', count($changes), date('d-m-Y H:i'));
            $mail->Body    = $this->buildBody($runId, $changes);
            $mail->AltBody = $this->buildTextBody($runId, $changes);

            $mail->send();
            $this->logger->info('Aamil change email sent to ' . implode(', ', $recipients) . ' (' . count($changes) . ' change(s))');
            return true;
        } catch (MailerException $e) {
            $this->logger->error('Aamil change email failed: ' . ($mail->ErrorInfo ?? $e->getMessage()));
            return false;
        }
    }

    /**
     * @return string[]
     */
    private function recipients(): array
    {
        $list = array_map('trim', explode(',', $this->config['MAIL_TO'] ?? ''));
        return array_values(array_filter($list, static fn(string $a) => filter_var($a, FILTER_VALIDATE_EMAIL) !== false));
    }

    /**
     * @param array<int, array<string, mixed>> $changes
     */
    private function buildBody(string $runId, array $changes): string
    {
        $rows = '';
        foreach ($changes as $i => $c) {
            $rows .= sprintf(
                '<tr><td>%d</td><td>%d</td><td>%s</td><td>%s</td><td>%s</td><td><b>%s</b></td></tr>',
                $i + 1,
                $c['jamaat_id'],
                htmlspecialchars($c['jamaat']),
                htmlspecialchars($c['jamiaat']),
                htmlspecialchars($c['old_its_id']),
                htmlspecialchars($c['new_its_id'])
            );
        }

        return '<p>The following Aamil Saheb change(s) were detected during the ITS Jamaat sync:</p>'
            . '<table border="1" cellpadding="6" cellspacing="0" style="border-collapse:collapse;font-family:Arial,sans-serif;font-size:13px">'
            . '<tr style="background:#f0f0f0"><th>#</th><th>Jamaat ID</th><th>Jamaat</th><th>Jamiaat</th><th>Old Aamil ITS</th><th>New Aamil ITS</th></tr>'
            . $rows
            . '</table>'
            . '<p style="color:#777;font-size:12px">Run ID: ' . htmlspecialchars($runId) . '<br>Time: ' . date('Y-m-d H:i:s') . '</p>';
    }

    /**
     * @param array<int, array<string, mixed>> $changes
     */
    private function buildTextBody(string $runId, array $changes): string
    {
        $lines = ["Aamil Saheb change(s) detected during ITS Jamaat sync:", ''];
        foreach ($changes as $c) {
            $lines[] = sprintf(
                '- [%d] %s (%s): %s -> %s',
                $c['jamaat_id'], $c['jamaat'], $c['jamiaat'], $c['old_its_id'], $c['new_its_id']
            );
        }
        $lines[] = '';
        $lines[] = "Run ID: {$runId}";

        return implode("\n", $lines);
    }
}
