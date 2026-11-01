<?php
declare(strict_types=1);

namespace App\Core;

final class App
{
    public function __construct(private Router $router)
    {
    }

    /** Monta o app carregando as rotas de app/routes.php. */
    public static function create(): self
    {
        $router = new Router();
        require dirname(__DIR__) . '/routes.php';
        return new self($router);
    }

    public function router(): Router
    {
        return $this->router;
    }

    /** Ponto de entrada web: cria o app e trata a requisicao. */
    public static function run(Request $request): Response
    {
        return self::create()->handle($request);
    }

    public function handle(Request $request): Response
    {
        try {
            Session::start((array) Config::get('session', []));
            return $this->router->dispatch($request);
        } catch (HttpException $e) {
            return $this->errorPage($e->status());
        } catch (\Throwable $e) {
            $this->log($e, $request);
            return $this->errorPage(500);
        }
    }

    private function errorPage(int $status): Response
    {
        $messages = [
            405 => 'Esta página não aceita esse tipo de acesso.',
            419 => 'Sua sessão expirou. Recarregue a página e tente novamente.',
        ];
        try {
            if (in_array($status, [403, 404, 500], true)) {
                $body = View::render("Layout/views/errors/$status.php", ['title' => 'Erro']);
            } else {
                $body = View::render('Layout/views/errors/message.php', [
                    'title' => 'Aviso',
                    'message' => $messages[$status] ?? 'Não foi possível concluir a ação.',
                ]);
            }
        } catch (\Throwable) {
            $body = '<!doctype html><meta charset="utf-8"><title>Erro</title><p>Ocorreu um erro. Tente novamente.</p>';
        }
        return Response::html($body, $status);
    }

    private function log(\Throwable $e, Request $request): void
    {
        $base = (string) Config::get('storage_path', APP_ROOT . '/storage');
        $dir = $base . '/logs';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $line = sprintf(
            "[%s] %s %s %s: %s in %s:%d\n%s\n",
            date('Y-m-d H:i:s'),
            $request->method(),
            $request->path(),
            get_class($e),
            $e->getMessage(),
            $e->getFile(),
            $e->getLine(),
            $e->getTraceAsString(),
        );
        @file_put_contents($dir . '/app.log', $line, FILE_APPEND | LOCK_EX);
    }
}
