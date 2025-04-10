<?php

require_once '../Include/Config.php';

use ChurchCRM\Authentication\AuthenticationManager;
use ChurchCRM\Authentication\Requests\LocalTwoFactorTokenRequest;
use ChurchCRM\Authentication\Requests\LocalUsernamePasswordRequest;
use ChurchCRM\dto\SystemURLs;
use ChurchCRM\Slim\Middleware\VersionMiddleware;
use ChurchCRM\Utils\InputUtils;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Factory\AppFactory;
use Slim\Views\PhpRenderer;
use Symfony\Component\DependencyInjection\ContainerBuilder;

require_once __DIR__ . '/../vendor/autoload.php';

$rootPath = str_replace('/session/index.php', '', $_SERVER['SCRIPT_NAME']);
$container = new ContainerBuilder();
$container->compile();
AppFactory::setContainer($container);
$app = AppFactory::create();
$app->setBasePath($rootPath . '/session');

require __DIR__ . '/../Include/slim/error-handler.php';

$app->addRoutingMiddleware();
$app->add(new VersionMiddleware());
$container = $app->getContainer();

require __DIR__ . '/routes/password-reset.php';

$app->get('/begin', 'beginSession');
$app->post('/begin', 'beginSession');
$app->get('/end', 'endSession');
$app->get('/two-factor', 'processTwoFactorGet');
$app->post('/two-factor', 'processTwoFactorPost');

function processTwoFactorGet(Request $request, Response $response, array $args): Response
{
    $renderer = new PhpRenderer('templates/');
    $curUser = AuthenticationManager::getCurrentUser();
    $pageArgs = [
        'sRootPath' => SystemURLs::getRootPath(),
        'user'      => $curUser,
    ];

    return $renderer->render($response, 'two-factor.php', $pageArgs);
}

function processTwoFactorPost(Request $request, Response $response, array $args): void
{
    $loginRequestBody = $request->getParsedBody();
    $request = new LocalTwoFactorTokenRequest($loginRequestBody['TwoFACode']);
    AuthenticationManager::authenticate($request);
}

function endSession(Request $request, Response $response, array $args): void
{
    AuthenticationManager::endSession();
}

function beginSession(Request $request, Response $response, array $args): Response
{
    $queryParams = $request->getQueryParams();
    $redirectPath = isset($queryParams['location']) ? urldecode($queryParams['location']) : null;
    $pageArgs = [
        'sRootPath'            => SystemURLs::getRootPath(),
        'localAuthNextStepURL' => AuthenticationManager::getSessionBeginURL($redirectPath),
        'forgotPasswordURL'    => AuthenticationManager::getForgotPasswordURL(),
    ];

    if ($request->getMethod() === 'POST') {
        $loginRequestBody = $request->getParsedBody();
        $userPassRequest = new LocalUsernamePasswordRequest($loginRequestBody['User'], $loginRequestBody['Password'], $redirectPath);
        $authenticationResult = AuthenticationManager::authenticate($userPassRequest);
        $pageArgs['sErrorText'] = $authenticationResult->message;
    }

    $renderer = new PhpRenderer('templates/');

    // Determine if appropriate to pre-fill the username field
    $pageArgs['prefilledUserName'] = InputUtils::filterSanitizeString($request->getQueryParams()['username']) ??
    InputUtils::filterSanitizeString($request->getServerParams()['username']) ??
        '';

    return $renderer->render($response, 'begin-session.php', $pageArgs);
}

$app->run();
