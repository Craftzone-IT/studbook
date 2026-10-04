<?php

declare(strict_types=1);

namespace Studbook\Controller;

use Studbook\Http\Request;
use Studbook\Http\Response;
use Studbook\Http\Session;
use Studbook\I18n\Translator;
use Studbook\SettingRepository;
use Studbook\View;

final class SettingsController
{
    /** @var \Closure(): SettingRepository */
    private \Closure $settings;

    /** @var \Closure(): array<string, mixed> */
    private \Closure $cameraStatus;

    /**
     * @param callable(): SettingRepository $settings
     * @param (callable(): array<string, mixed>)|null $cameraStatus see {@see CameraController::status()}
     */
    public function __construct(private readonly View $view, callable $settings, ?callable $cameraStatus = null)
    {
        $this->settings = \Closure::fromCallable($settings);
        $this->cameraStatus = \Closure::fromCallable($cameraStatus ?? static fn (): array => []);
    }

    public function show(Request $request): Response
    {
        return Response::html($this->view->render('settings', [
            'title' => t('settings.title'),
            'languages' => array_keys(Translator::LANGUAGES),
            'now' => new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
            'camera' => ($this->cameraStatus)(),
        ]));
    }

    public function save(Request $request): Response
    {
        $language = $request->input('ui_language');
        if (!Translator::isSupported($language)) {
            Session::flash('error', t('settings.invalid_language'));

            return Response::redirect(url('/settings'));
        }
        ($this->settings)()->set(SettingRepository::UI_LANGUAGE, $language);
        // Translate the confirmation in the newly chosen language.
        $translator = new Translator(dirname(__DIR__, 2) . '/lang', $language);
        Session::flash('success', $translator->translate('settings.saved'));

        return Response::redirect(url('/settings'));
    }
}
