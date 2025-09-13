<?php
require_once __DIR__ . '/../vendor/autoload.php';

// Create new PDF document
$pdf = new TCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);

// Set document information
$pdf->SetCreator('BOSS-KIAN');
$pdf->SetAuthor('BOSS-KIAN');
$pdf->SetTitle('Joint Affidavit (Two Disinterested Person)');
$pdf->SetSubject('Joint Affidavit (Two Disinterested Person)');

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

// Joint Affidavit (Two Disinterested Person) content
$html = <<<EOD
<div style="text-align:center; font-size:12pt;">
    <b>JOINT AFFIDAVIT<br/>(Two Disinterested Person)</b>
</div>
<br/>
<div style="text-align:left; font-size:11pt;">
    REPUBLIC OF THE PHILIPPINES )<br/>
    PROVINCE OF LAGUNA      ) SS<br/>
    CITY OF CABUYAO         )<br/><br/>
    WE, ____________________________ and ____________________________, Filipinos, both of legal age, and permanent residents of ____________________________, after being duly sworn in accordance with law hereby depose and say;<br/><br/>
    1. That we are not in any way related by affinity or consanguinity to: ____________________________, child of the spouses ____________________________ and ____________________________;<br/><br/>
    2. That we know for a fact that he/she was born on ________________ at ____________________________;<br/><br/>
    3. That we know the circumstances surrounding the birth of the said ____________________________, considering that we are present during delivery as we are well acquainted with his/her parents, being family friend and neighbors;<br/><br/>
    4. That we are executing this affidavit in order to furnish by secondary evidence as to the fact concerning the date and place of birth of ____________________________ in the absence of his/her Birth Certificate and let this instrument be useful for whatever legal purpose it may serve best;<br/><br/>
    IN WITNESS WHEREOF, we have hereunto set our hands this ________________ in Cabuyao City, Laguna.<br/><br/>
    <table style="width:100%;">
        <tr>
            <td style="width:50%; text-align:center;">_____________________________<br/>Affiant<br/>ID ____________________</td>
            <td style="width:50%; text-align:center;">_____________________________<br/>Affiant<br/>ID ____________________</td>
        </tr>
    </table><br/>
    SUBSCRIBED AND SWORN to before me this ________________ at the City of Cabuyao, Laguna, Philippines, the affiants exhibited to me their respective proof of identification indicated below their name, attesting that the above statement are true and executed freely and voluntarily;<br/><br/>
    WITNESS my hand the date and place above-written.<br/><br/>
    Doc. No. _____<br/>
    Page No. _____<br/>
    Book No. _____<br/>
    Series of _____<br/>
</div>
EOD;

$pdf->writeHTML($html, true, false, true, false, '');

// Output PDF
$pdf->Output('Joint_Affidavit_Two_Disinterested_Person.pdf', 'D'); 