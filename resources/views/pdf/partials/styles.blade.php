{{-- Styles communs des documents PDF (DomPDF) : noir et blanc, gris pour les filets seulement. --}}
<style>
    @page { margin: 14mm 14mm 16mm 14mm; }
    * { font-family: 'DejaVu Sans', sans-serif; }
    body { font-size: 10.5pt; color: #000; margin: 0; line-height: 1.35; }
    h1 { font-size: 15pt; margin: 0; }
    h2 { font-size: 11.5pt; margin: 14pt 0 4pt; padding-bottom: 2pt; border-bottom: 1pt solid #000; }
    .subtitle { font-size: 12pt; margin: 2pt 0 10pt; }
    table { width: 100%; border-collapse: collapse; }
    td, th { padding: 3.5pt 5pt; text-align: left; vertical-align: top; }
    table.info th { width: 28%; font-weight: normal; color: #333; }
    table.info td, table.info th { border-bottom: 0.5pt solid #999; }
    table.lines th { font-size: 9pt; border-bottom: 1pt solid #000; }
    table.lines td { border-bottom: 0.5pt solid #bbb; }
    table.grid th { font-size: 8.5pt; border-bottom: 1pt solid #000; }
    table.grid td { font-size: 9pt; border-bottom: 0.5pt solid #bbb; }
    table.grid tr.sum td { font-weight: bold; border-top: 1pt solid #000; border-bottom: none; }
    .num { text-align: right; white-space: nowrap; }
    .small { font-size: 9pt; }
    .strong td { font-weight: bold; }
    .big td { font-size: 12pt; }
    table.totals { margin-top: 14pt; border: 1pt solid #000; }
    table.totals td { border-bottom: 0.5pt solid #bbb; }
    table.totals .boxed td { border: 1.5pt solid #000; }
    .words { font-style: italic; font-size: 9.5pt; }
    .tag { font-size: 8pt; font-weight: bold; border: 0.75pt solid #000; padding: 0 3pt; letter-spacing: 0.5pt; }
    .note { font-size: 9pt; margin: 4pt 0 0; }
    .footer { font-size: 8.5pt; color: #333; margin-top: 16pt; border-top: 0.5pt solid #999; padding-top: 4pt; }
</style>
