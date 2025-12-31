<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessDmarcReportJob;
use App\Models\DmarcMonitor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class DmarcReportController extends Controller
{
    /**
     * Receive DMARC report via webhook/email
     * This endpoint can be called by mail servers or email processing services
     */
    public function receive(Request $request)
    {
        // Accept both JSON (webhook) and multipart/form-data (email)
        $xmlContent = null;
        $reportEmail = null;
        
        // Method 1: Check if XML is in JSON body
        if ($request->has('xml')) {
            $xmlContent = $request->input('xml');
            $reportEmail = $request->input('to') 
                ?? $request->input('recipient')
                ?? $request->input('report_email');
        }
        // Method 2: Check if XML is uploaded as file
        elseif ($request->hasFile('xml')) {
            $xmlContent = file_get_contents($request->file('xml')->getRealPath());
            $reportEmail = $request->input('to') 
                ?? $request->input('recipient');
        }
        // Method 3: Check if XML is in attachment field
        elseif ($request->has('attachment')) {
            $xmlContent = $request->input('attachment');
            $reportEmail = $request->input('to') 
                ?? $request->input('recipient');
        }
        // Method 4: Try to get from raw body (for direct XML POST)
        else {
            $rawContent = $request->getContent();
            // Check if it looks like XML
            if (strpos(trim($rawContent), '<?xml') === 0 || strpos(trim($rawContent), '<feedback') === 0) {
                $xmlContent = $rawContent;
            }
        }

        // Method 5: Try to extract from email headers (for email processing services)
        if (!$reportEmail) {
            $reportEmail = $request->header('X-Original-To')
                ?? $request->header('X-Envelope-To')
                ?? $request->header('To');
        }

        // If still no XML, try to parse email format (for services like Mailgun webhooks)
        if (!$xmlContent && $request->has('body-plain')) {
            // Some email services send body in body-plain
            $body = $request->input('body-plain');
            if (strpos($body, '<?xml') !== false || strpos($body, '<feedback') !== false) {
                $xmlContent = $body;
            }
        }

        // Extract from email attachments (for multipart emails)
        if (!$xmlContent && $request->hasFile('attachment-1')) {
            $xmlContent = file_get_contents($request->file('attachment-1')->getRealPath());
        }

        if (!$xmlContent) {
            Log::warning('DMARC report received but no XML content found', [
                'headers' => $request->headers->all(),
                'content_type' => $request->header('Content-Type'),
                'has_files' => $request->hasFile('xml'),
            ]);
            return response()->json(['error' => 'No DMARC report XML found'], 400);
        }

        // Try to extract report email from XML if not provided
        if (!$reportEmail) {
            try {
                libxml_use_internal_errors(true);
                $xml = simplexml_load_string($xmlContent);
                if ($xml && isset($xml->report_metadata->email)) {
                    $reportEmail = (string) $xml->report_metadata->email;
                }
                libxml_clear_errors();
            } catch (\Exception $e) {
                Log::warning('Failed to parse DMARC XML for email extraction', [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Log successful reception
        Log::info('DMARC report received', [
            'report_email' => $reportEmail,
            'xml_length' => strlen($xmlContent),
            'content_type' => $request->header('Content-Type'),
        ]);

        // Dispatch job to process the report
        ProcessDmarcReportJob::dispatch($xmlContent, $reportEmail);

        return response()->json(['message' => 'DMARC report received and queued for processing'], 202);
    }

    /**
     * Receive DMARC report via email (for Mailpit or similar)
     * This is a simplified version that expects the XML in the request
     */
    public function receiveEmail(Request $request)
    {
        // This endpoint would be called by an email processing service
        // For now, we'll accept the same format as receive()
        return $this->receive($request);
    }
}

