<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <title>Коммерческое предложение</title>
    <style>
        body { font: 12px 'DejaVu Sans', sans-serif; color: #192639; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 10px; border-bottom: 1px solid #b7c3d0; text-align: left; }
        h1 { font-size: 21px; }
    </style>
</head>
<body>
<h1>Коммерческое предложение №{{ $version->version }}</h1>
<p>{{ $version->snapshot['company'] }} — {{ $version->snapshot['title'] }}</p>
@if ($change = $version->snapshot['booking_change'] ?? null)
<h2>Новые условия размещения</h2>
<p>Заезд {{ \Illuminate\Support\Carbon::parse($change['arrival_on'])->format('d.m.Y') }} · выезд {{ \Illuminate\Support\Carbon::parse($change['departure_on'])->format('d.m.Y') }} · гостей {{ $change['guests'] }}</p>
<ul>
    @foreach ($version->snapshot['stay_rooms'] as $room)
        <li>{{ $room['name'] }} — {{ $room['rooms'] }} ном.</li>
    @endforeach
</ul>
@endif
<table>
    <thead><tr><th>Позиция</th><th>Количество</th><th>Цена, руб.</th></tr></thead>
    <tbody>
    @foreach ($version->snapshot['items'] as $item)
        <tr><td>{{ $item['name'] }}</td><td>{{ $item['quantity'] }}</td><td>{{ number_format($item['price_minor'] / 100, 2, ',', ' ') }}</td></tr>
    @endforeach
    </tbody>
</table>
<p>Скидка {{ $version->snapshot['discount'] }}%</p>
<p><strong>Итого {{ number_format($version->snapshot['total_minor'] / 100, 2, ',', ' ') }} руб.</strong></p>
</body>
</html>
