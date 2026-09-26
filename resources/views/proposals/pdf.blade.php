<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>{{ $proposal->proposal_no }}</title>
    <style>
        body { font-family: Helvetica, Arial, sans-serif; font-size: 12px; color: #111; line-height: 1.5; }
        .proposal-cover { margin-bottom: 24px; border-bottom: 2px solid #111; padding-bottom: 12px; }
        .proposal-cover h1 { font-size: 22px; margin: 0 0 4px; }
        .proposal-cover p { margin: 2px 0; color: #444; }
        .proposal-section { margin-bottom: 18px; }
        .proposal-heading { font-size: 15px; margin: 0 0 6px; }
        .proposal-body p { margin: 4px 0; }
        table.proposal-table { width: 100%; border-collapse: collapse; margin: 8px 0; }
        table.proposal-table th, table.proposal-table td { border: 1px solid #999; padding: 5px 8px; text-align: left; font-size: 11px; }
        table.proposal-table th { background: #eee; }
        table.proposal-signoff { width: 100%; border-collapse: collapse; margin: 8px 0; }
        table.proposal-signoff td { border: 1px solid #999; padding: 5px 8px; font-size: 11px; }
        .proposal-signatures { margin-top: 24px; }
        .proposal-signatures div { margin-bottom: 28px; }
        .proposal-signline { border-top: 1px solid #111; padding-top: 4px; width: 60%; color: #444; }
        mark.proposal-unknown { background: #fdd; }
    </style>
</head>
<body>
    <div class="proposal-cover">
        <h1>{{ $proposal->proposal_no }}</h1>
        <p>{{ $values['school'] ?? '' }}</p>
        <p>{{ $values['contact'] ?? '' }} · {{ $values['date'] ?? '' }}</p>
    </div>

    @include('proposals.document', ['blocks' => $blocks])
</body>
</html>
