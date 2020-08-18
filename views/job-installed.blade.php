@component('email.indicator', ['status' => 'success'])
    Your application is installed.
@endcomponent

@component('mail::message')
{{-- Body --}}
# Howdy!

{{ $appname }} has been successfully installed on [{{ $uri }}]({{ $proto }}{{$uri}})!

Laravel runs in development mode to help facilitate debugging. You can change environments in a few steps:

1. Edit `{{ $approot }}/.env`.
2. Change APP_ENV from `development` to `production`
3. Rebuild your configuration, `{{ $approot }}/artisan config:cache`

@if (SSH_EMBED_TERMINAL)
You can access the terminal quickly within {{ PANEL_BRAND }} via **Dev** > **Terminal**
@endif

## Laravel Resources
* [Laracasts](https://laracasts.com)
* [Laravel Documentation](https://laravel.com/docs)
* [/r/laravel](https://reddit.com/r/laravel)

---

Security is important with any application, so extra steps are taken to reduce
the risk of hackers. By default **Maximum** Fortification is enabled. This will
work for most people, but if you run into any problems change Fortification to
**Minimum**.

Here's how to do it:

1. Visit **Web** > **Web Apps** in {{PANEL_BRAND}}
2. Select {{ $appname }} installed under **{{$uri}}**
3. Select **Fortification (MIN)** under _Actions_

You can learn more about [Fortification technology]({{MISC_KB_BASE}}/control-panel/understanding-fortification/) within the [knowledgebase]({{MISC_KB_BASE}}).

@include('email.webapps.common-footer')
@endcomponent