<?php

namespace Covey\Laravel\Http\Controllers;

use Covey\Laravel\Audit;
use Covey\Laravel\TinkerOutput;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Psy\Configuration;
use Psy\Shell;
use Throwable;

// Code in the application's context, as `php artisan tinker` would run it.
// This is the write side, and it is honest about it: there is no way to let
// an agent run PHP and keep it from changing things, which is why it sits
// behind its own token, its own switch and — on the covey side — its own
// guard-rail subject (laravel:tinker), where an organisation puts the
// approval it wants.
class TinkerController
{
    public function __invoke(Request $request): JsonResponse
    {
        $t0 = microtime(true);
        if (! config('covey.tinker.enabled')) {
            return response()->json(['error' => 'tinker is switched off in this application (COVEY_TINKER_ENABLED)'], 403);
        }
        $code = (string) $request->input('code', '');
        if (trim($code) === '') {
            return response()->json(['error' => 'code is empty'], 422);
        }

        $config = new Configuration([
            'updateCheck' => 'never',
            'usePcntl' => false,
            'rawOutput' => true,
            'colorMode' => Configuration::COLOR_MODE_DISABLED,
            'interactiveMode' => Configuration::INTERACTIVE_MODE_DISABLED,
            'configFile' => null,
            'historyFile' => null,
        ]);
        $output = new TinkerOutput;
        // Both the shell and its configuration hold an output; whatever is
        // written through either lands in the buffer, nothing on stdout.
        $config->setOutput($output);
        $shell = new Shell($config);
        $shell->addInput($code);

        $timeout = max(1, (int) config('covey.tinker.timeout_seconds', 30));
        set_time_limit($timeout);
        $error = null;
        try {
            $shell->run(null, $output);
        } catch (Throwable $e) {
            $error = get_class($e).': '.$e->getMessage();
        }
        $text = $output->fetch();
        Audit::record('tinker', $request->attributes->get('covey.ability'), ['code' => $code, 'error' => $error], $t0);

        return response()->json([
            'output' => $text,
            'error' => $error,
        ], $error === null ? 200 : 422);
    }
}
