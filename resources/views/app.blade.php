<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>Ekklesia</title>
    @vite(['resources/js/app.js'])
</head>
<body>
    <div id="app" data-initial-grid='@json($initialGrid ?? [])'></div>
</body>
</html>

