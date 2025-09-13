<?php
require_once __DIR__ . '/../vendor/autoload.php';

// Create new PDF document
$pdf = new TCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);

// Set document information
$pdf->SetCreator('BOSS-KIAN');
$pdf->SetAuthor('BOSS-KIAN');
$pdf->SetTitle('Sworn Affidavit (Solo Parent)');
$pdf->SetSubject('Sworn Affidavit (Solo Parent)');

// Set default header data
$pdf->SetHeaderData('', 0, '', '');
$pdf->setPrintHeader(false);
$pdf->setPrintFooter(false);

// Set margins
$pdf->SetMargins(20, 20, 20);
$pdf->SetAutoPageBreak(TRUE, 20);

// Set font
$pdf->SetFont('times', '', 12);

// Add a page
$pdf->AddPage();

// Sworn Affidavit (Solo Parent) content
$html = <<<EOD
<div style="text-align:center; font-size:12pt;">
    <b>SWORN AFFIDAVIT<br/>(SOLO PARENT)</b>
</div>
<br/>
<div style="text-align:left; font-size:11pt;">
    REPUBLIC OF THE PHILIPPINES)<br/>
    PROVINCE OF LAGUNA<br/>
    CITY OF CABUYAO) S.S.<br/><br/>
    That I, ____________________________, Filipino citizen, of legal age, single / married / widow, with residence and postal address at ____________________________, City of Cabuyao, Laguna after having been duly sworn in accordance with law hereby depose and state that;<br/><br/>
    1. That I am a single parent and the Mother / Father of:<br/>
    <table style="width:100%; border-collapse: collapse;">
        <tr>
            <td style="width:50%; padding:5px; border:1px solid #000;">Name</td>
            <td style="width:50%; padding:5px; border:1px solid #000;">Age</td>
        </tr>
        <tr>
            <td style="padding:5px; border:1px solid #000;">____________________________</td>
            <td style="padding:5px; border:1px solid #000;">____________________________</td>
        </tr>
        <tr>
            <td style="padding:5px; border:1px solid #000;">____________________________</td>
            <td style="padding:5px; border:1px solid #000;">____________________________</td>
        </tr>
    </table><br/>
    2. That I am solely taking care and providing for my said child needs and everything indispensable for his/her/ wellbeing for ________________ year/s now since his / her biological Father / Mother,<br/>
    □ Left the family home and abandoned us;<br/>
    □ Died last ____________________________<br/>
    □ (Other reason, please state) ____________________________<br/><br/>
    3. I am attesting to the fact that I am not cohabiting with anybody to date;<br/><br/>
    4. I am currently;<br/>
    □ Employed and earning Php ____________________________ per month;<br/>
    □ Self-employed and earning Php ____________________________ per month, from my Job as ____________________________<br/>
    □ Un-employed and dependent upon my ____________________________<br/><br/>
    5. I am executing this affidavit to attest the truthfulness of the above-stated facts and let this instrument be useful in whatever legal purpose it may serve.<br/><br/>
    IN WITNESS WHEREOF, I have hereunto affixed my signature this ____________________________ in the City of Cabuyao, Laguna, Philippines<br/><br/>
    ____________________________<br/>
    AFFIANT<br/><br/>
    SUBSCRIBED AND SWORN before me this ____________________________ in the City of Cabuyao, Province of Laguna, Philippines, affiant personally appeared and exhibiting to me her ____________________________ as competent proof of identity.<br/><br/>
    Doc. No. _____<br/>
    Page No. _____<br/>
    Book No. _____<br/>
    Series of _____<br/>
</div>
EOD;

$pdf->writeHTML($html, true, false, true, false, '');

// Output PDF
$pdf->Output('Sworn_Affidavit_Solo_Parent.pdf', 'D'); 