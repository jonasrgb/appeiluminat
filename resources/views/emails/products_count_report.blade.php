@component('mail::message')
# Raport săptămânal - Produse fara stoc

Salut,

Mai jos ai situația actualizată pentru produsele din magazine:

@component('mail::table')
| Magazin    | Link produse      | Număr Produse |
|:-----------|:-----------------------|:--------------|
@foreach($reports as $report)
| {{ $report['store'] }} | [Vezi produse]({{ $report['collection_url'] }}) | {{ $report['products_count'] }} |
@endforeach
@endcomponent

Mulțumim,<br>
{{ config('app.name') }}
@endcomponent
