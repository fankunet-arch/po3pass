<?php
/**
 * TopTea POS - TICKETBAI Compliance Handler
 * Handles the generation of TICKETBAI-specific compliance data.
 * Engineer: Gemini | Date: 2025-10-26 | Revision: 2.0 (Anulación Logic)
 */

require_once __DIR__ . '/ComplianceHandler.php';

class TICKETBAIHandler implements ComplianceHandler
{
    public function generateComplianceData(PDO $pdo, array $invoiceData, ?string $previousHash): array
    {
        $dataToHash = json_encode([
            'series' => $invoiceData['series'],
            'number' => $invoiceData['number'],
            'issued_at' => $invoiceData['issued_at'],
            'total' => $invoiceData['final_total'],
            'previous_hash' => $previousHash ?? '',
        ]);

        $currentHash = hash('sha256', $dataToHash);
        $signature = "--- SIMULATED TICKETBAI SIGNATURE FOR HASH: " . substr($currentHash, 0, 16) . "... ---";
        $qrContent = "https://tbai.euskadi.eus/qr?id=TBAITEST&num={$invoiceData['number']}&fecha={$invoiceData['issued_at']}&hash=" . substr($currentHash, 0, 8);

        return [
            'previous_hash' => $previousHash,
            'hash' => $currentHash,
            'signature' => $signature,
            'qr_content' => $qrContent,
            'system_version' => 'TopTeaPOS v1.0-TBAI',
        ];
    }
    
    public function generateCancellationData(PDO $pdo, array $originalInvoice, array $cancellationData, ?string $previousHash): array
    {
        $original_tbai_data = json_decode($originalInvoice['compliance_data'], true);
        
        $dataToHash = json_encode([
            'cancellation_reason' => $cancellationData['cancellation_reason'],
            'original_hash' => $original_tbai_data['hash'],
            'issued_at' => $cancellationData['issued_at'],
            'previous_hash' => $previousHash ?? '',
        ]);

        $currentHash = hash('sha256', $dataToHash);
        $signature = "--- SIMULATED TICKETBAI CANCELLATION SIGNATURE ---";
        
        return [
            'record_type' => 'RF-Anulacion',
            'previous_hash' => $previousHash,
            'hash' => $currentHash,
            'signature' => $signature,
            'original_invoice_details' => [
                'series' => $originalInvoice['series'],
                'number' => $originalInvoice['number'],
                'hash' => $original_tbai_data['hash']
            ],
            'system_version' => 'TopTeaPOS v1.0-TBAI',
        ];
    }
}