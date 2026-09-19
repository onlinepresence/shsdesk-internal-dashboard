@php
    $currency = $pricing['currency'] ?? 'GHS';
    $isCustom = (bool) ($pricing['is_custom'] ?? false);
    $validUntil = $issuedAt->copy()->addDays(30);
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Proforma Invoice - {{ $contact['college_name'] ?? $deployment->school_name }}</title>
    <style>
        @page {
            margin: 1.5cm;
        }
        body {
            font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif;
            font-size: 13px;
            line-height: 1.4;
            color: #2d3748;
            margin: 0;
            padding: 24px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
        }
        .header-table td {
            vertical-align: top;
        }
        .logo {
            font-size: 26px;
            font-weight: 800;
            color: #0f172a;
            letter-spacing: -0.5px;
            margin: 0;
            line-height: 1;
        }
        .logo span {
            color: #059669;
        }
        .company-subtitle {
            font-size: 9px;
            text-transform: uppercase;
            letter-spacing: 2px;
            color: #64748b;
            margin-top: 3px;
            font-weight: 600;
        }
        .title {
            text-align: right;
            font-size: 20px;
            font-weight: 700;
            color: #0f172a;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            line-height: 1;
        }
        .meta-text {
            text-align: right;
            font-size: 11px;
            color: #64748b;
            margin-top: 5px;
        }
        .meta-text strong {
            color: #0f172a;
        }
        .divider {
            border-top: 2px solid #e2e8f0;
            margin: 15px 0 25px 0;
        }
        .info-table td {
            width: 50%;
            vertical-align: top;
            padding: 0;
        }
        .info-header {
            font-size: 11px;
            text-transform: uppercase;
            font-weight: 700;
            letter-spacing: 1px;
            color: #64748b;
            margin-bottom: 8px;
        }
        .info-body {
            font-size: 13px;
            color: #0f172a;
            line-height: 1.5;
        }
        .info-body strong {
            font-size: 14px;
            display: block;
            margin-bottom: 3px;
        }
        .items-table {
            margin-top: 25px;
        }
        .items-table th {
            background-color: #0f172a;
            color: #ffffff;
            font-weight: 700;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            padding: 10px 12px;
            text-align: left;
            border: none;
        }
        .items-table td {
            padding: 12px 12px;
            border-bottom: 1px solid #e2e8f0;
            font-size: 12px;
            vertical-align: top;
        }
        .items-table td.num {
            text-align: right;
            font-family: Courier, monospace;
            font-weight: 600;
            font-size: 13px;
        }
        .items-table .sub-item td {
            color: #059669;
            font-weight: 500;
            background-color: #f0fdf4;
            border-bottom: 1px solid #bbf7d0;
            padding: 8px 12px;
        }
        .items-table .sub-header td {
            font-weight: 700;
            font-size: 11px;
            text-transform: uppercase;
            background-color: #f8fafc;
            color: #475569;
            padding: 8px 12px;
            border-bottom: 1px solid #e2e8f0;
        }
        .totals-table {
            width: 45%;
            float: right;
            margin-top: 15px;
        }
        .totals-table td {
            padding: 8px 10px;
            font-size: 12px;
            border-bottom: 1px solid #f1f5f9;
        }
        .totals-table td.label {
            color: #64748b;
            font-weight: 600;
            text-align: right;
        }
        .totals-table td.val {
            text-align: right;
            font-family: Courier, monospace;
            font-weight: 700;
            font-size: 13px;
            color: #0f172a;
            width: 110px;
        }
        .totals-table tr.grand-total td {
            font-size: 14px;
            font-weight: 800;
            border-top: 2px solid #0f172a;
            border-bottom: 2px solid #0f172a;
            background-color: #f8fafc;
        }
        .totals-table tr.grand-total td.val {
            color: #059669;
            font-size: 15px;
        }
        .notes-section {
            width: 50%;
            float: left;
            margin-top: 20px;
            font-size: 11px;
            color: #64748b;
            line-height: 1.5;
        }
        .notes-title {
            font-weight: 700;
            text-transform: uppercase;
            color: #0f172a;
            margin-bottom: 6px;
            letter-spacing: 0.5px;
        }
        .custom-notice {
            margin-top: 25px;
            padding: 16px;
            background-color: #fffbeb;
            border: 1px solid #fcd34d;
            color: #92400e;
            font-size: 13px;
            line-height: 1.5;
        }
        .toolbar {
            max-width: 900px;
            margin: 0 auto 20px auto;
            display: flex;
            justify-content: flex-end;
            gap: 8px;
        }
        .toolbar button {
            font-family: inherit;
            font-size: 13px;
            font-weight: 600;
            padding: 8px 16px;
            border-radius: 6px;
            border: 1px solid #cbd5e1;
            background: #ffffff;
            color: #0f172a;
            cursor: pointer;
        }
        .toolbar button.primary {
            background: #0f172a;
            border-color: #0f172a;
            color: #ffffff;
        }
        .sheet {
            max-width: 900px;
            margin: 0 auto;
            background: #ffffff;
        }
        .footer {
            text-align: center;
            font-size: 10px;
            color: #94a3b8;
            border-top: 1px solid #e2e8f0;
            padding-top: 10px;
            margin-top: 30px;
        }
        @media print {
            body {
                padding: 0;
            }
            .toolbar {
                display: none;
            }
            .sheet {
                max-width: none;
            }
        }
    </style>
</head>
<body>

    <div class="toolbar">
        <button type="button" onclick="window.close()">Close</button>
        <button type="button" class="primary" onclick="window.print()">Print / Save as PDF</button>
    </div>

    <div class="sheet">
        <table class="header-table">
            <tr>
                <td>
                    <div class="logo">Flow<span>Edu</span></div>
                    <div class="company-subtitle">by Matme Inc.</div>
                </td>
                <td>
                    <div class="title">Proforma Invoice</div>
                    <div class="meta-text">
                        Invoice No: <strong>{{ $invoiceNo }}</strong><br>
                        Date: <strong>{{ $issuedAt->format('M d, Y') }}</strong><br>
                        Validity: <strong>30 Days (Expires {{ $validUntil->format('M d, Y') }})</strong>
                    </div>
                </td>
            </tr>
        </table>

        <div class="divider"></div>

        <table class="info-table">
            <tr>
                <td>
                    <div class="info-header">Prepared For:</div>
                    <div class="info-body">
                        <strong>{{ $contact['college_name'] ?? $deployment->school_name }}</strong>
                        @if (! empty($contact['url'] ?? $deployment->url))
                            {{ $contact['url'] ?? $deployment->url }}<br>
                        @endif
                        Band: {{ $pricing['band_label'] ?? '—' }}
                    </div>
                </td>
                <td>
                    <div class="info-header">Issued By:</div>
                    <div class="info-body">
                        <strong>Matme Inc.</strong>
                        Systems Integration &amp; Licensing Team<br>
                        Email: successinnovativehub@gmail.com<br>
                        Phone: 0249100268 / Accra, Ghana
                    </div>
                </td>
            </tr>
        </table>

        @if ($isCustom)
            <div class="custom-notice">
                This school ({{ $pricing['band_label'] ?? 'past the auto-priced bands' }}) needs a custom quote —
                there is no automatic total. Record the agreed terms and share the final figures manually.
            </div>
        @else
            <table class="items-table">
                <thead>
                    <tr>
                        <th style="width: 50%;">Item Description</th>
                        <th style="text-align: right; width: 25%;">Upfront Setup / License</th>
                        <th style="text-align: right; width: 25%;">Annual Renewal (Y2+)</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td>
                            <strong>Core Academic License</strong><br>
                            <span style="font-size: 10px; color: #64748b;">Includes: Academic Structure, Student Profiles, Grading &amp; Transcript Portals, Attendance, and Basic Administration. Scaled for {{ $pricing['band_label'] }}.</span>
                        </td>
                        <td class="num">{{ number_format($pricing['core_upfront_final'], 2) }}</td>
                        <td class="num">{{ number_format($pricing['core_renewal_final'], 2) }}</td>
                    </tr>
                    @if (($pricing['founding_discount_upfront'] ?? 0) > 0 || ($pricing['founding_discount_renew'] ?? 0) > 0)
                        <tr class="sub-item">
                            <td>
                                &nbsp;&nbsp;&bull; Founding Client Discount ({{ number_format(($pricing['founding_discount_rate'] ?? 0) * 100, 0) }}% off core)
                            </td>
                            <td class="num">-{{ number_format($pricing['founding_discount_upfront'] ?? 0, 2) }}</td>
                            <td class="num">-{{ number_format($pricing['founding_discount_renew'] ?? 0, 2) }}</td>
                        </tr>
                    @endif

                    @if (! empty($pricing['modules']))
                        <tr class="sub-header">
                            <td colspan="3">Selected Modular Extensions (Multiplier: {{ $pricing['multiplier'] }}x)</td>
                        </tr>
                        @foreach ($pricing['modules'] as $mod)
                            <tr>
                                <td>
                                    <strong>{{ $mod['label'] }}</strong>
                                </td>
                                <td class="num">{{ number_format($mod['onetime'], 2) }}</td>
                                <td class="num">{{ number_format($mod['renew'], 2) }}</td>
                            </tr>
                        @endforeach
                        @if (($pricing['bundle_discount_onetime'] ?? 0) > 0 || ($pricing['bundle_discount_renew'] ?? 0) > 0)
                            <tr class="sub-item">
                                <td>
                                    &nbsp;&nbsp;&bull; Bundle Discount ({{ number_format(($pricing['bundle_discount_rate'] ?? 0) * 100, 0) }}% off modules)
                                </td>
                                <td class="num">-{{ number_format($pricing['bundle_discount_onetime'] ?? 0, 2) }}</td>
                                <td class="num">-{{ number_format($pricing['bundle_discount_renew'] ?? 0, 2) }}</td>
                            </tr>
                        @endif
                    @endif

                    <tr class="sub-header">
                        <td colspan="3">Hosting &amp; Integration Services</td>
                    </tr>
                    <tr>
                        <td>
                            <strong>Hosting Environment Setup</strong><br>
                            <span style="font-size: 10px; color: #64748b;">{{ $pricing['hosting_label'] }}</span>
                        </td>
                        <td class="num">{{ number_format($pricing['hosting_setup_fee'] ?? 0, 2) }}</td>
                        <td class="num">0.00</td>
                    </tr>

                    @if (! empty($pricing['addons']))
                        @foreach ($pricing['addons'] as $addon)
                            <tr>
                                <td>
                                    <strong>{{ $addon['label'] }}</strong>
                                </td>
                                <td class="num">{{ number_format($addon['price'], 2) }}</td>
                                <td class="num">0.00</td>
                            </tr>
                        @endforeach
                    @endif

                    @if (! empty($pricing['trainings']))
                        <tr class="sub-header">
                            <td colspan="3">Training &amp; Workshops</td>
                        </tr>
                        @foreach ($pricing['trainings'] as $t)
                            <tr>
                                <td>
                                    {{ $t['label'] }}
                                </td>
                                <td class="num">{{ number_format($t['price'], 2) }}</td>
                                <td class="num">0.00</td>
                            </tr>
                        @endforeach
                    @endif
                </tbody>
            </table>

            <div style="width: 100%;">
                <div class="notes-section">
                    <div class="notes-title">Terms &amp; Conditions</div>
                    1. All pricing is in <strong>{{ $currency }}</strong>.<br>
                    2. Annual Renewals are billed at the start of each academic year.<br>
                    3. Setup and implementation begin upon mutual agreement and signing of Service Level Agreement (SLA).<br>
                    4. This proforma invoice is valid for exactly 30 days from date of issue.
                </div>

                <table class="totals-table">
                    <tr>
                        <td class="label">Subtotal Upfront:</td>
                        <td class="val">{{ number_format($pricing['upfront_total'], 2) }}</td>
                    </tr>
                    <tr>
                        <td class="label">Subtotal Renewal:</td>
                        <td class="val">{{ number_format($pricing['renew_total'], 2) }}</td>
                    </tr>
                    <tr class="grand-total">
                        <td class="label">Year 1 Total ({{ $currency }}):</td>
                        <td class="val">{{ number_format($pricing['upfront_total'], 2) }}</td>
                    </tr>
                    <tr>
                        <td class="label" style="font-size: 10px;">Recurring (Y2+ / yr):</td>
                        <td class="val" style="font-size: 11px;">{{ number_format($pricing['renew_total'], 2) }}</td>
                    </tr>
                </table>
                <div style="clear: both;"></div>
            </div>
        @endif

        <div class="footer">
            FlowEdu is a trademark of Matme Inc. &bull; Thank you for choosing FlowEdu
        </div>
    </div>

</body>
</html>
