<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Heptapod</title>
    </head>
    <body>
        <x-login-link :email="\Database\Seeders\DatabaseSeeder::DEMO_EMAIL" :label="\Database\Seeders\DatabaseSeeder::DEMO_EMAIL" />
    </body>
</html>
