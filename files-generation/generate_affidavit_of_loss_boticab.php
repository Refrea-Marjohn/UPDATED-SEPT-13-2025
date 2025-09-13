<?php
require_once __DIR__ . '/../vendor/autoload.php';

// Create new PDF document
$pdf = new TCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);

// Set document information
$pdf->SetCreator('BOSS-KIAN');
$pdf->SetAuthor('BOSS-KIAN');
$pdf->SetTitle('Affidavit of Loss (Boticab Booklet/ID)');
$pdf->SetSubject('Affidavit of Loss (Boticab Booklet/ID)');

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

// Affidavit of Loss (Boticab Booklet/ID) content
$html = <<<EOD
<div style="text-align:center; font-size:12pt;">
    <b>AFFIDAVIT OF LOSS<br/>(Boticab Booklet/ID)</b>
</div>
<br/>
<div style="text-align:left; font-size:11pt;">
    REPUBLIC OF THE PHILIPPINES )
    PROVINCE OF LAGUNA )
    CITY OF CABUYAO ) S.S.
    I, ____________________________, Filipino, of legal age, and with residence and currently residing at ____________________________, after having been duly sworn to in accordance with law do hereby depose and state:<br/><br/>
    1. That I am the owner/possessor of a Boticab Booklet/ID.<br/><br/>
    2. That unfortunately, the said Boticab Booklet/ID was lost under the following circumstance:<br/>
    ___________________________________________<br/>
    ___________________________________________<br/>
    ___________________________________________<br/><br/>
    3. That despite diligent efforts to retrieve the said Boticab Booklet/ID, the same can no longer be restored and therefore considered lost;<br/><br/>
    4. That I am executing this statement to attest to all above facts and for whatever legal purpose it may serve in accordance with law.<br/><br/>
    AFFIANT FURTHER SAYETH NAUGHT.<br/><br/>
    IN WITNESS WHEREOF, I have hereunto set my hand this ________________ in the City of Cabuyao, Laguna.<br/><br/>
    ____________________________<br/>
    AFFIANT<br/><br/>
    SUBSCRIBED AND SWORN, to before me this ________________ at the City of Cabuyao, Laguna affiant exhibiting to me his/her ____________________________ as respective proof of identity.<br/><br/>
    Doc. No. _______<br/>
    Page No. _____<br/>
    Book No. _____<br/>
    Series of 2025<br/>
</div>
EOD;

$pdf->writeHTML($html, true, false, true, false, '');

// Output PDF
$pdf->Output('Affidavit_of_Loss_Boticab.pdf', 'D'); 