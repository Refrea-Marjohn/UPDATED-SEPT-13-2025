<?php
require_once __DIR__ . '/../vendor/autoload.php';

// Create new PDF document
$pdf = new TCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);

// Set document information
$pdf->SetCreator('BOSS-KIAN');
$pdf->SetAuthor('BOSS-KIAN');
$pdf->SetTitle('Sworn Affidavit of Solo Parent');
$pdf->SetSubject('Sworn Affidavit of Solo Parent');

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

// Sworn Affidavit of Solo Parent content
$html = <<<EOD
<div style="text-align:center; font-size:12pt;">
    <b>SWORN AFFIDAVIT OF SOLO PARENT</b>
</div>
<br/>
<div style="text-align:left; font-size:11pt;">
    REPUBLIC OF THE PHILIPPINES)<br/>
    PROVINCE OF LAGUNA      )<br/>
    CITY OF CABUYAO         ) S.S.<br/><br/>
    That I, ____________________________, ____________________________, Filipino Citizen, of legal age, single/married, and with residence and postal address at ____________________________, after having been duly sworn to in accordance with law, hereby depose and say ;<br/><br/>
    1. That I am a single parent and the Mother/Father of the following child / children namely:<br/>
    <table style="width:100%; border-collapse: collapse;">
        <tr>
            <td style="width:60%; padding:5px; border:1px solid #000; text-align:center;">Name</td>
            <td style="width:40%; padding:5px; border:1px solid #000; text-align:center;">Age</td>
        </tr>
        <tr><td style="padding:5px; border:1px solid #000;">&nbsp;</td><td style="padding:5px; border:1px solid #000;">&nbsp;</td></tr>
        <tr><td style="padding:5px; border:1px solid #000;">&nbsp;</td><td style="padding:5px; border:1px solid #000;">&nbsp;</td></tr>
        <tr><td style="padding:5px; border:1px solid #000;">&nbsp;</td><td style="padding:5px; border:1px solid #000;">&nbsp;</td></tr>
    </table><br/>
    2. That I am solely taking care and providing for my said child's / children's needs and everything indispensable for his / her / their wellbeing for _______ year/s now since his / her / their biological Mother/Father<br/>
    <span style="display:inline-block;width:20px;border:1px solid #000;height:12px;vertical-align:middle;"></span> left the family home and abandoned us;<br/>
    <span style="display:inline-block;width:20px;border:1px solid #000;height:12px;vertical-align:middle;"></span> died last ____________________________;<br/>
    <span style="display:inline-block;width:20px;border:1px solid #000;height:12px;vertical-align:middle;"></span> (other reason please state) ________________;<br/><br/>
    <b>3. I am attesting to the fact that I am not cohabiting with anybody to date;</b><br/><br/>
    4. I am currently :<br/>
    <span style="display:inline-block;width:20px;border:1px solid #000;height:12px;vertical-align:middle;"></span> Employed and earning Php ____________ per month;<br/>
    <span style="display:inline-block;width:20px;border:1px solid #000;height:12px;vertical-align:middle;"></span> Self-employed and earning Php ____________ per month, from my job as ________________;<br/>
    <span style="display:inline-block;width:20px;border:1px solid #000;height:12px;vertical-align:middle;"></span> Un-employed and dependent upon my ________________;<br/><br/>
    5. That I am executing this affidavit, to affirm the truth and veracity of the foregoing statements and be use for whatever legal purpose it may serve.<br/><br/>
    IN WITNESS WHEREOF, I have hereunto affixed my signature this ________________ at the City of Cabuyao, Laguna.<br/><br/>
    ____________________________<br/>
    AFFIANT<br/><br/>
    SUBSCRIBED AND SWORN to before me this ________________ at the City of Cabuyao, Laguna, affiant personally appeared and exhibiting to me his/her ____________________________ with ID No. ________________ as competent proof of identity.<br/><br/>
    Doc. No. _____<br/>
    Page No. _____<br/>
    Book No. _____<br/>
    Series of 2025<br/>
</div>
EOD;

$pdf->writeHTML($html, true, false, true, false, '');

// Output PDF
$pdf->Output('Sworn_Affidavit_of_Solo_Parent.pdf', 'D'); 