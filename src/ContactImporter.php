<?php

declare(strict_types=1);

namespace RioSlum\HiringTest;

class ContactImporter
{
    public function __construct(
        private MockCrmClient $client,
        private int $batchSize = 3,
        private int $maxRetries = 2
    ) {
    }

    public function run(string $inputPath, string $outputPath): array
    {
        $rawContacts        = $this->readContacts($inputPath);
        $totalRecords       = count($rawContacts);

        $normalizedContacts = $this->normalizeContacts($rawContacts);
        $validContacts      = $this->filterValidContacts($normalizedContacts);
        $invalidCount       = count($normalizedContacts) - count($validContacts);

        $mergedContacts     = $this->mergeDuplicates($validContacts);
        $duplicatesMerged   = count($validContacts) - count($mergedContacts);

        $result = [
            'summary' => [
                'total_records'      => $totalRecords,
                'valid_records'      => count($validContacts),
                'invalid_records'    => $invalidCount,
                'duplicates_merged'  => $duplicatesMerged,
                'attempted_imports'  => 0,
                'successful_imports' => 0,
                'failed_imports'     => 0,
            ],
            'imported' => [],
            'failed'   => [],
            'skipped'  => array_values(array_filter($normalizedContacts, fn($c) => !$this->isValidEmail($c['email'] ?? ''))),
        ];

        $batches = array_chunk($mergedContacts, $this->batchSize);

        foreach ($batches as $batch) {
            foreach ($batch as $contact) {

                $result['summary']['attempted_imports']++;
                $importResult = $this->importContactWithRetry($contact);

                if ($importResult['success']) {
                    $result['summary']['successful_imports']++;
                    $result['imported'][] = array_merge($contact, ['crm_id' => $importResult['id']]);
                } else {
                    $result['summary']['failed_imports']++;
                    $result['failed'][] = array_merge($contact, ['error' => $importResult['error']]);
                }
                
            }
        }

        file_put_contents($outputPath, json_encode($result, JSON_PRETTY_PRINT));

        return $result;
    }

    private function readContacts(string $path): array
    {
        if (!file_exists($path)) {
            throw new \RuntimeException("Input file not found: {$path}");
        }

        $content = file_get_contents($path);
        $contacts = json_decode($content, true);

        if (!is_array($contacts)) {
            throw new \RuntimeException("Invalid JSON format in input file");
        }

        return $contacts;
    }

    private function normalizeContacts(array $contacts): array
    {
        return array_map(function ($contact) {
            if (isset($contact['email'])) {
                $contact['email'] = strtolower(trim($contact['email']));
            }
            return $contact;
        }, $contacts);
    }

    private function isValidEmail(string $email): bool
    {
        return !empty($email) && filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }

    private function filterValidContacts(array $contacts): array
    {
        return array_values(array_filter($contacts, function ($contact) {
            return $this->isValidEmail($contact['email'] ?? '');
        }));
    }

    private function mergeDuplicates(array $contacts): array
    {
        $merged = [];

        foreach ($contacts as $contact) {
            $email = $contact['email'];

            if (!isset($merged[$email])) {
                $merged[$email] = $contact;
            } else {
                $merged[$email] = $this->mergeContactData($merged[$email], $contact);
            }
        }

        return array_values($merged);
    }

    private function mergeContactData(array $existing, array $new): array
    {
        foreach ($new as $key => $value) {
            if (empty($existing[$key]) && !empty($value)) {
                $existing[$key] = $value;
            } elseif (!empty($value) && strlen((string)$value) > strlen((string)$existing[$key])) {
                $existing[$key] = $value;
            }
        }

        return $existing;
    }

    private function importContactWithRetry(array $contact): array
    {
        $attempt = 0;

        while ($attempt <= $this->maxRetries) {
            $response = $this->client->sendContact($contact);

            if ($response['success']) {
                return $response;
            }

            if ($response['status'] === 429) {
                $retryAfter = $response['retry_after'] ?? 1;
                sleep($retryAfter);
                $attempt++;
                continue;
            }

            if ($response['status'] === 500) {
                $attempt++;
                if ($attempt <= $this->maxRetries) {
                    usleep(100000);
                    continue;
                }
            }

            return $response;
        }

        return [
            'success' => false,
            'error' => 'Max retries exceeded',
        ];
    }
}
