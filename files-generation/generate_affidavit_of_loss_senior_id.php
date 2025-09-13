<?php
require_once __DIR__ . '/../vendor/autoload.php';

// Create new PDF document
$pdf = new TCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);

// Set document information
$pdf->SetCreator('BOSS-KIAN');
$pdf->SetAuthor('BOSS-KIAN');
$pdf->SetTitle('Affidavit of Loss (Senior ID)');
$pdf->SetSubject('Affidavit of Loss (Senior ID)');

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

// Affidavit of Loss (Senior ID) content
$html = <<<EOD
<div style="text-align:center; font-size:12pt;">
    <b>AFFIDAVIT OF LOSS<br/>(SENIOR ID)</b>
</div>
<br/>
<div style="text-align:left; font-size:11pt;">
    REPUBLIC OF THE PHILIPPINES<br/>
    PROVINCE OF LAGUNA ) S.S<br/>
    CITY OF CABUYAO )<br/><br/>
    I, ____________________________, Filipino, of legal age, and with residence ____________________________ and currently residing at ____________________________, after having been sworn in accordance with law hereby depose and state:<br/><br/>
    1. That I am the ____________________________ of ____________________________, who is the lawful owner of a Senior Citizen ID issued by OSCA-Cabuyao;<br/><br/>
    2. That unfortunately the said Senior ID was lost under the following circumstance:<br/>
    ___________________________________________<br/>
    ___________________________________________<br/>
    ___________________________________________<br/><br/>
    3. That despite diligent efforts to retrieve the said Senior ID, the same can no longer be restored and therefore considered lost;<br/><br/>
    4. I am executing this affidavit to attest to the truth of the foregoing facts and for whatever legal intents and purposes whatever legal intents and purposes.<br/><br/>
    AFFIANT FURTHER SAYETH NAUGHT.<br/><br/>
    IN WITNESS WHEREOF, I have hereunto set my hand this _____________, in the City of Cabuyao, Laguna.<br/><br/>
    ____________________________<br/>
    AFFIANT<br/><br/>
    SUBSCRIBED AND SWORN to before me, this _____________ in the City of Cabuyao, Laguna, affiant exhibiting to me his/her ____________________________ as valid proof of identification.<br/><br/>
    Doc. No. _______;<br/>
    Page No. _______;<br/>
    Book No. _______;<br/>
    Series of _______.<br/>
</div>
EOD;

$pdf->writeHTML($html, true, false, true, false, '');

// Output PDF
$pdf->Output('Affidavit_of_Loss_Senior_ID.pdf', 'D'); 