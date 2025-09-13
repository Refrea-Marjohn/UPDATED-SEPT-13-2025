<?php
require_once __DIR__ . '/../vendor/autoload.php';

// Create new PDF document
$pdf = new TCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);

// Set document information
$pdf->SetCreator('BOSS-KIAN');
$pdf->SetAuthor('BOSS-KIAN');
$pdf->SetTitle('Joint Affidavit of Two Disinterested Person (Solo Parent)');
$pdf->SetSubject('Joint Affidavit of Two Disinterested Person (Solo Parent)');

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

// Joint Affidavit of Two Disinterested Person content
$html = <<<EOD
<div style="text-align:center; font-size:12pt;">
    <b>JOINT AFFIDAVIT OF TWO DISINTERESTED PERSON<br/>(SOLO PARENT)</b>
</div>
<br/>
<div style="text-align:left; font-size:11pt;">
    REPUBLIC OF THE PHILIPPINES )<br/>
    PROVINCE OF LAGUNA      ) SS<br/>
    CITY OF CABUYAO         )<br/><br/>
    WE, ____________________________ and ____________________________, Filipinos, both of legal age, and permanent residents of ____________________________, after being duly sworn in accordance with law hereby depose and say ;<br/><br/>
    1. That we are not in any way related by affinity or consanguinity to : ____________________________, a resident of ____________________________, City of Cabuyao, Laguna;<br/><br/>
    2. That we know her / him as a single parent and the Mother / Father of this children:<br/>
    <table style="width:100%; border-collapse: collapse;">
        <tr>
            <td style="width:60%; padding:5px; border:1px solid #000; text-align:center;">Name</td>
            <td style="width:40%; padding:5px; border:1px solid #000; text-align:center;">Age</td>
        </tr>
        <tr><td style="padding:5px; border:1px solid #000;">&nbsp;</td><td style="padding:5px; border:1px solid #000;">&nbsp;</td></tr>
        <tr><td style="padding:5px; border:1px solid #000;">&nbsp;</td><td style="padding:5px; border:1px solid #000;">&nbsp;</td></tr>
        <tr><td style="padding:5px; border:1px solid #000;">&nbsp;</td><td style="padding:5px; border:1px solid #000;">&nbsp;</td></tr>
    </table><br/>
    3. That she/he is solely taking care and providing for her/his children's needs and everything indispensable for her / his well-being since the biological Father /Mother abandoned her / his children;<br/><br/>
    <b>4. That we know for a fact that she/he is not cohabitating with any other man / woman since she / he become a solo parent until present;</b><br/><br/>
    5. That we execute this affidavit to attest to the truth of the foregoing and let this instrument be useful for whatever legal intents it may serve.<br/><br/>
    IN WITNESS WHEREOF, we have hereunto set our hands this ________________, in the City of Cabuyao, Laguna.<br/><br/>
    <b>AFFIANTS:</b><br/><br/>
    <table style="width:100%;">
        <tr>
            <td style="width:50%; text-align:center;">_____________________________<br/>Valid ID No.__________________________</td>
            <td style="width:50%; text-align:center;">_____________________________<br/>Valid ID No.__________________________</td>
        </tr>
    </table><br/>
    SUBSCRIBED AND SWORN TO before me this date above mentioned at the City of Cabuyao, Laguna , affiants exhibiting to me their respective proofs of identity personally attesting that the foregoing statements are true to the best of their knowledge and beliefs.<br/><br/>
    Doc. No. _____<br/>
    Book No. _____<br/>
    Page No. _____<br/>
    Series of _____<br/>
</div>
EOD;

$pdf->writeHTML($html, true, false, true, false, '');

// Output PDF
$pdf->Output('Joint_Affidavit_of_Two_Disinterested_Person.pdf', 'D'); 