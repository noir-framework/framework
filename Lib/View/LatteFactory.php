<?php

declare(strict_types=1);

namespace Noirapi\Lib\View;

use App\Lib\Macros as AppMacros;
use Latte\Bridges\Tracy\TracyExtension;
use Latte\Essential\TranslatorExtension;
use Latte\Feature;
use Noirapi\Config;
use Noirapi\Helpers\Template;
use Noirapi\Interfaces\Translator;
use Noirapi\Lib\Prebuilt;

/**
 * Every Latte engine the framework renders with, built in one place so `noirapi prebuild` can build the very same
 * engines and compile the templates for them (see LatteEngine).
 *
 * Normal apps compile into temp/ and recompile a template when it changes. A prebuilt app has no cache directory:
 * the compiled classes come from prebuilt/latte/.
 *
 * @psalm-api
 */
final class LatteFactory
{
    /**
     * The engine Noirapi\Lib\View renders pages with.
     *
     * @param string|null $language the request's language (a key of the `languages` config), null without one
     */
    public static function view(?string $language, Translator $translator, bool $dev = false): LatteEngine
    {
        $latte = self::engine();
        $latte->addExtension(new FilterExtension());
        $latte->addExtension(new Macros());
        $latte->addFunction('renderTemplate', static function ($template, array $data) {
            $render = new Template();
            $render->setTemplate($template);

            return $render->print($data);
        });

        /**
         * @noinspection PhpUndefinedClassInspection
         * @noinspection RedundantSuppression
         * @noinspection PhpUndefinedNamespaceInspection
         */
        if (class_exists(AppMacros::class)) {
            /**
             * @noinspection PhpParamsInspection
             * @noinspection RedundantSuppression
             */
            $latte->addExtension(new AppMacros());
        }

        $latte->addExtension(new TranslatorExtension([$translator, 'translate'], $language));

        if ($dev) {
            $latte->addExtension(new TracyExtension());
        }

        $latte->setFeature(Feature::StrictParsing);
        $latte->setFeature(Feature::StrictTypes);

        return $latte;
    }

    /**
     * A plain engine with the framework's filters: mail, Noirapi\Helpers\Template, an app's PDFs. The app may add
     * filters (runtime only) and run its LatteSetup on it.
     */
    public static function basic(): LatteEngine
    {
        $latte = self::engine();
        $latte->addExtension(new FilterExtension());

        return $latte;
    }

    private static function engine(): LatteEngine
    {
        $latte = new LatteEngine();
        // templates named __<name> come from app/layouts/; ids are relative to the code root
        $latte->setLoader(new LatteLoader());

        if (! Prebuilt::active()) {
            $latte->setCacheDirectory(Config::getTemp());
            // recompile a template when it (or an extension) changes
            $latte->setAutoRefresh();
        }

        return $latte;
    }
}
