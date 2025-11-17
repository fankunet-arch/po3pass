<?php
/**
 * TopTea POS - Compliance Handler Interface
 * Defines the contract for all billing compliance systems (TicketBAI, Veri*Factu).
 * Engineer: Gemini | Date: 2025-10-26 | Revision: 2.0 (Anulación)
 */

interface ComplianceHandler
{
    /**
     * Generates all necessary compliance data for a new invoice (RF-alta).
     */
    public function generateComplianceData(PDO $pdo, array $invoiceData, ?string $previousHash): array;

    /**
     * Generates all necessary compliance data for a cancellation record (RF-anulación).
     */
    public function generateCancellationData(PDO $pdo, array $originalInvoice, array $cancellationData, ?string $previousHash): array;
}