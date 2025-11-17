<?php
/**
 * TopTea POS - Veri*Factu Compliance Handler
 * Handles the generation of Veri*Factu-specific compliance data.
 * Engineer: Gemini | Date: 2025-10-26 | Revision: 2.0 (Anulación Logic)
 */

require_once __DIR__ . '/ComplianceHandler.php';

class VERIFACTUHandler implements ComplianceHandler
{
    public function generateComplianceData(PDO $pdo, array $invoiceData, ?string $previousHash): array
    {
        $dataToHash = json_encode([
            'invoice_series' => $invoiceData['series'],
            'invoice_number' => $invoiceData['number'],
            'issue_datetime' => $invoiceData['issued_at'],
            'total_amount' => $invoiceData['final_total'],
            'previous_invoice_hash' => $previousHash ?? '',
        ]);

        $currentHash = hash('sha256', $dataToHash);
        $qrContent = "URL:https://www.agenciatributaria.gob.es/verifactu?s={$invoiceData['series']}&n={$invoiceData['number']}&i={$invoiceData['issued_at']}&h=" . substr($currentHash, 0, 8);

        return [
            'previous_hash' => $previousHash,
            'hash' => $currentHash,
            'qr_content' => $qrContent,
            'system_version' => 'TopTeaPOS v1.0-VERIFACTU',
        ];
    }
    
    public function generateCancellationData(PDO $pdo, array $originalInvoice, array $cancellationData, ?string $previousHash): array
    {
        $dataToHash = json_encode([
            'cancellation_reason' => $cancellationData['cancellation_reason'],
            'original_series' => $originalInvoice['series'],
            'original_number' => $originalInvoice['number'],
            'issued_at' => $cancellationData['issued_at'],
            'previous_hash' => $previousHash ?? '',
        ]);

        $currentHash = hash('sha256', $dataToHash);
        
        return [
            'record_type' => 'RF-Anulacion',
            'previous_hash' => $previousHash,
            'hash' => $currentHash,
            'original_invoice_details' => [
                'series' => $originalInvoice['series'],
                'number' => $originalInvoice['number'],
            ],
            'system_version' => 'TopTeaPOS v1.0-VERIFACTU',
        ];
    }
}