<?php

namespace App\Services;

use App\Models\Proposal;
use Dompdf\Dompdf;

class ProposalPdf
{
    public function render(Proposal $version): string
    {
        $pdf = new Dompdf;
        $pdf->loadHtml(view('proposal', ['version' => $version])->render(), 'UTF-8');
        $pdf->render();

        return $pdf->output();
    }

    public function filename(Proposal $version): string
    {
        return 'proposal-'.$version->deal_id.'-'.$version->version.'.pdf';
    }
}
