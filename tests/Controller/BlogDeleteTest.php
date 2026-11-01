<?php

namespace App\Tests\Controller;

use App\Entity\Blog;
use App\Entity\Image;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Borrar una noticia desde su edición: se va la noticia y, con ella, sus medios
 * (que cuelgan del post sin clave foránea) y el fichero de cada uno.
 */
class BlogDeleteTest extends WebTestCase
{
    public function testEliminarNoticiaBorraTambienSusMediosYSusFicheros(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $router = static::getContainer()->get(UrlGeneratorInterface::class);

        $admin = $em->getRepository(User::class)->loadUserByIdentifier('admin');
        if ($admin === null) {
            throw new \RuntimeException('Fixtures sin User admin; carga UserFixtures en db_test.');
        }
        $client->loginUser($admin);

        $blog = (new Blog())->setTitle('Noticia que se borra')->setContent('<p>Texto</p>');
        $em->persist($blog);
        $em->flush();

        // Un medio real, con su fichero en disco, enganchado como lo hace el editor.
        $fileName = 'test-blog-delete-' . bin2hex(random_bytes(4)) . '.jpg';
        $image = (new Image())->setTitle('Foto')->setImage($fileName)->setObjectClass('blog')->setForeignKey((string) $blog->getId())->setSingle(true);
        $em->persist($image);
        $em->flush();
        $path = $image->getAbsolutePath();
        @mkdir(\dirname($path), 0777, true);
        file_put_contents($path, 'x');
        $blogId = $blog->getId();
        $imageId = $image->getId();

        $crawler = $client->request('GET', $router->generate('blog_edit', ['id' => $blogId]));
        $this->assertResponseIsSuccessful();
        $form = $crawler->filter('#blog-delete-form')->form();
        $client->submit($form);
        $this->assertResponseRedirects($router->generate('blog'));

        $em->clear();
        $this->assertNull($em->getRepository(Blog::class)->find($blogId), 'La noticia debe desaparecer.');
        $this->assertNull($em->getRepository(Image::class)->find($imageId), 'Sus medios también.');
        $this->assertFileDoesNotExist($path, 'Y el fichero de cada medio.');
    }
}
