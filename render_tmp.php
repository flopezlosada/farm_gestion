<?php
use App\Entity\User; use App\Kernel;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
require __DIR__ . '/vendor/autoload.php';
(new \Symfony\Component\Dotenv\Dotenv())->bootEnv(__DIR__ . '/.env');
$_SERVER['APP_ENV'] = $_ENV['APP_ENV'] = 'test';
$_SERVER['DATABASE_URL'] = $_ENV['DATABASE_URL'] = 'mysql://db:db@db:3306/db?serverVersion=8.0';
$kernel = new Kernel('test', true); $kernel->boot();
$c = $kernel->getContainer()->get('test.service_container');
$user = $c->get('doctrine')->getRepository(User::class)->findOneBy(['username' => $argv[1]]);
$token = new UsernamePasswordToken($user, 'main', $user->getRoles());
$session = $c->get('session.factory')->createSession();
$session->set('_security_main', serialize($token)); $session->save();
$request = Request::create($argv[2], 'GET', [], [$session->getName() => $session->getId()]);
$request->setSession($session);
echo $kernel->handle($request)->getContent();
