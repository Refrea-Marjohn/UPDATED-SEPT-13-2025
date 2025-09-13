<?php
require_once __DIR__ . '/../vendor/autoload.php';

// Create new PDF document
$pdf = new TCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);

// Set document information
$pdf->SetCreator('BOSS-KIAN');
$pdf->SetAuthor('BOSS-KIAN');
$pdf->SetTitle('Sworn Statement of Mother');
$pdf->SetSubject('Sworn Statement of Mother');

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

// Sworn Statement of Mother content
$html = <<<EOD
<div style="text-align:center; font-size:12pt;">
    <b>SWORN STATEMENT OF MOTHER</b>
</div>
<br/>
<div style="text-align:left; font-size:11pt;">
    REPUBLIC OF THE PHILIPPINES)<br/>
    PROVINCE OF LAGUNA      ) SS<br/>
    CITY OF CABUYAO         )<br/><br/>
    I, ____________________________, of legal age, Filipino, single and with residence and postal address at ____________________________, after being duly sworn in accordance with law, hereby depose and say that:<br/><br/>
    1. That I am the biological mother of ____________________________, who was born out of wedlock on ________________ at ____________________________;<br/><br/>
    2. The biological father of my child is ____________________________;<br/><br/>
    3. That the birth of the above named child was not registered in the City Civil Registry of ____________ City, due to negligence on our part.<br/><br/>
    4. That I am now taking the appropriate action to register the birth of my said child.<br/><br/>
    5. I am executing this affidavit to attest to the truth of the foregoing facts and be use for whatever legal purpose it may serve.<br/><br/>
    IN WITNESS WHEREOF, I have hereunto set my hands this ____________________ in the City of Cabuyao, Laguna.<br/><br/>
    ____________________________<br/>
    AFFIANT<br/><br/>
    SUBCRIBED AND SWORN to before me this ____________________ at the City of Cabuyao, Laguna affiant exhibiting to me her ____________________________ as respective proof of identity.<br/><br/>
    Doc. No. _____<br/>
    Book No. _____<br/>
    Page No. _____<br/>
    Series of _____<br/>
</div>
EOD;

$pdf->writeHTML($html, true, false, true, false, '');

// Output PDF
$pdf->Output('Sworn_Statement_of_Mother.pdf', 'D'); 