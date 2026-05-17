<?php

declare(strict_types=1);

namespace PushPull\Secrets;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception construction is not HTML output.

use RuntimeException;

final class SopsSecretEnvelopeResolver implements SecretEnvelopeResolverInterface
{
    public function supports(array $envelope): bool
    {
        return (string) ($envelope['provider'] ?? '') === 'sops';
    }

    public function resolve(array $envelope): string
    {
        $encrypted = (string) ($envelope['encrypted'] ?? '');
        $path = trim((string) ($envelope['path'] ?? ''));
        $format = trim((string) ($envelope['format'] ?? 'json'));

        if ($encrypted === '') {
            throw new RuntimeException('SOPS secret envelope is missing encrypted content.');
        }

        if ($path === '') {
            throw new RuntimeException('SOPS secret envelope is missing its value path.');
        }

        if ($format !== 'json') {
            throw new RuntimeException(sprintf('Unsupported SOPS secret envelope format "%s".', $format));
        }

        $decrypted = $this->decryptJson($encrypted);
        $decoded = json_decode($decrypted, true);

        if (! is_array($decoded)) {
            throw new RuntimeException('Decrypted SOPS secret content is not valid JSON.');
        }

        $value = $this->readDotPath($decoded, $path);

        if (! is_scalar($value)) {
            throw new RuntimeException(sprintf('Resolved SOPS secret path "%s" is not a scalar value.', $path));
        }

        $normalized = trim((string) $value);

        if ($normalized === '') {
            throw new RuntimeException(sprintf('Resolved SOPS secret path "%s" is empty.', $path));
        }

        return $normalized;
    }

    private function decryptJson(string $encrypted): string
    {
        $binary = trim((string) getenv('PUSHPULL_SOPS_BIN'));
        $binary = $binary !== '' ? $binary : 'sops';
        $tempFile = wp_tempnam('pushpull-sops-secret.json');

        if (! is_string($tempFile) || $tempFile === '') {
            throw new RuntimeException('Unable to allocate a temporary file for SOPS decryption.');
        }

        if (file_put_contents($tempFile, $encrypted) === false) {
            wp_delete_file($tempFile);

            throw new RuntimeException('Unable to write the SOPS secret envelope to a temporary file.');
        }

        $command = [
            $binary,
            '--decrypt',
            '--input-type',
            'json',
            '--output-type',
            'json',
            $tempFile,
        ];

        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        // phpcs:ignore Generic.PHP.ForbiddenFunctions.Found -- PushPull needs to invoke the local sops binary to decrypt age-encrypted envelopes.
        $process = proc_open($command, $descriptorSpec, $pipes);

        if (! is_resource($process)) {
            wp_delete_file($tempFile);

            throw new RuntimeException('Unable to start the SOPS decrypt process.');
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- proc_open() pipes are not handled through WP_Filesystem.
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- proc_open() pipes are not handled through WP_Filesystem.
        fclose($pipes[1]);
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- proc_open() pipes are not handled through WP_Filesystem.
        fclose($pipes[2]);
        $exitCode = proc_close($process);
        wp_delete_file($tempFile);

        if ($exitCode !== 0) {
            $message = trim((string) $stderr);
            $message = $message !== '' ? $message : 'SOPS decryption failed.';

            throw new RuntimeException($message);
        }

        return (string) $stdout;
    }

    /**
     * @param array<string, mixed> $decoded
     */
    private function readDotPath(array $decoded, string $path): mixed
    {
        $value = $decoded;

        foreach (explode('.', $path) as $segment) {
            if ($segment === '' || ! is_array($value) || ! array_key_exists($segment, $value)) {
                throw new RuntimeException(sprintf('Resolved SOPS secret path "%s" does not exist.', $path));
            }

            $value = $value[$segment];
        }

        return $value;
    }
}
