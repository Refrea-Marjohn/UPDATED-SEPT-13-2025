<?php
require_once __DIR__ . '/../vendor/autoload.php';

// Create new PDF document
$pdf = new TCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);

// Set document information
$pdf->SetCreator('BOSS-KIAN');
$pdf->SetAuthor('BOSS-KIAN');
$pdf->SetTitle('Affidavit of Loss');
$pdf->SetSubject('Affidavit of Loss');

// Set default header data
$pdf->SetHeaderData('', 0, '', '');
$pdf->setPrintHeader(false);
$pdf->setPrintFooter(false);

// Set margins
$pdf->SetMargins(25, 15, 25);
$pdf->SetAutoPageBreak(FALSE);

// Set font
$pdf->SetFont('times', '', 11);

// Add a page
$pdf->AddPage();

// Exact spacing to match the image precisely
$html = <<<EOD
<div style="font-size:11pt; line-height:1.2;">
    <br/><br/><br/>
    
    <div style="margin-bottom:10px;">
        REPUBLIC OF THE PHILIPPINES)<br/>
        &nbsp;&nbsp;&nbsp;PROVINCE OF LAGUNA;&nbsp;&nbsp;&nbsp;)&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;<b>S.S</b><br/>
        &nbsp;&nbsp;&nbsp;CITY OF CABUYAO&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;)<br/>
    </div>
    
    <br/><br/><br/>
    <div style="text-align:center; font-size:12pt; font-weight:bold; margin-top:-15px 0;">
        AFFIDAVIT OF LOSS
    </div>
    <br/>
    
    <div style="text-align:justify; margin-bottom:15px;">
        I, _________________________________, Filipino, of legal age, and with<br/>
        residence ____________ and ____________ currently residing at<br/>
        _________________________________________________, after having been sworn<br/>
        in accordance with law hereby depose and state:
    </div>
    
    <br/>
    
    <div style="text-align:justify;">
        1. &nbsp;&nbsp;That I am the true and lawful owner/possessor of<br/>
        &nbsp;&nbsp;&nbsp;&nbsp;&nbsp; _____________________________________________________________;
        <br/><br/>
        
        2. &nbsp;&nbsp;That unfortunately the said ________________ was lost under the following<br/>
        &nbsp;&nbsp;&nbsp;&nbsp;&nbsp; circumstance:<br/>
        &nbsp;&nbsp;&nbsp;&nbsp;&nbsp; ________________________________________________________________<br/>
        &nbsp;&nbsp;&nbsp;&nbsp;&nbsp; ________________________________________________________________<br/>
        &nbsp;&nbsp;&nbsp;&nbsp;&nbsp; ________________________________________________________________<br/>
        &nbsp;&nbsp;&nbsp;&nbsp;&nbsp; ________________________________________________________________;
        <br/><br/>
        
        3. &nbsp;&nbsp;That despite diligent search, the same can no longer be restored to date;
        <br/><br/>
        
        4. &nbsp;&nbsp;I am executing this affidavit to attest to the truth of the foregoing facts and<br/>
        &nbsp;&nbsp;&nbsp;&nbsp;&nbsp; for whatever legal intents and purposes.
    </div>
    
    <br/><br/>
    <div style="text-align:center; margin-bottom:10px;">
        AFFIANT FURTHER SAYETH NAUGHT.
    </div>
    
    <br/>
    <div style="text-align:justify; margin-bottom:15px;">
        IN WITNESS WHEREOF, I have hereunto set my hand this<br/>
        _________________, in the City of Cabuyao, Laguna.
    </div>
    
    <br/><br/>
    <div style="text-align:center; margin:15px 0;">
        _______________________<br/>
        <b>AFFIANT</b>
    </div>
    
    <br/>
    <div style="text-align:justify; margin-bottom:15px;">
        SUBSCRIBED &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;AND &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;SWORN &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;to &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;before &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;me, &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;this<br/>
        _________________________ in the City of Cabuyao, Laguna, affiant exhibiting<br/>
        to me his/her _____________________ as valid proof of identification.
    </div>
    
    <br/><br/>
    <div style="text-align:left;">
        Doc. No._______<br/>
        Page No._______<br/>
        Book No._______<br/>
        Series of _______
    </div>
</div>
EOD;

$pdf->writeHTML($html, true, false, true, false, '');

// Output PDF
$pdf->Output('Affidavit_of_Loss.pdf', 'D'); 