<?php

namespace Tests\Playbloom\Satisfy\Controller;

use org\bovigo\vfs\vfsStreamDirectory;
use org\bovigo\vfs\vfsStreamFile;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Tests\Playbloom\Satisfy\Traits\VfsTrait;

class RepositoryControllerTest extends WebTestCase
{
    use VfsTrait;

    protected function setUp(): void
    {
        $this->vfsSetup();
    }

    protected function tearDown(): void
    {
        $this->vfsTearDown();
    }

    public function testRepositoryIndex()
    {
        $client = self::createClient();
        $crawler = $client->request('GET', '/admin');
        $response = $client->getResponse();

        $this->assertEquals(Response::HTTP_OK, $response->getStatusCode());
        $code = $crawler->filterXPath('//code');
        $this->assertJson($code->text());
        $config = json_decode($code->text());
        $this->assertNotEmpty($config->repositories);
        if (isset($config->repositories[0]->options)) {
            $this->assertNotEmpty($config->repositories[0]->options);
        }
    }

    public function testRepositoryCRUD()
    {
        $client = self::createClient();
        $client->disableReboot();
        $crawler = $client->request('GET', '/admin/new');
        $response = $client->getResponse();

        $this->assertEquals(Response::HTTP_OK, $response->getStatusCode());
        $form = $crawler->filterXPath('//form');
        $this->assertEquals(Request::METHOD_POST, strtoupper($form->attr('method')));

        // form validation must fail due to invalid url
        $client->submit($form->form());
        $response = $client->getResponse();
        $this->assertEquals(Response::HTTP_OK, $response->getStatusCode());

        $client->submit(
          $form->form(),
          [
            'repository' => ['type' => 'package'],
          ]
        );
        $response = $client->getResponse();
        $this->assertEquals(Response::HTTP_OK, $response->getStatusCode());

        $url = 'git@github.com:YourAccount/YourRepo.git';
        $type = 'git';
        $request = ['type' => $type, 'url' => $url];
        $client->submit(
            $form->form(),
            ['repository' => $request]
        );
        $response = $client->getResponse();

        $this->assertEquals(Response::HTTP_FOUND, $response->getStatusCode());

        $this->assertTrue($this->vfsRoot->hasChild('satis.json'));
        /** @var vfsStreamFile $configHandle */
        $configHandle = $this->vfsRoot->getChild('satis.json');
        $config = $configHandle->getContent();

        $this->assertJson($config);
        $config = json_decode($config);
        $this->assertNotEmpty($config);

        $this->assertObjectHasAttribute('repositories', $config);
        $this->assertEquals($url, $config->repositories[0]->url);
        $this->assertEquals('git', $config->repositories[0]->type);
        $this->assertEquals('dist', $config->repositories[0]->{'installation-source'});

        // update repository
        $params = [
            'type' => $type2 = 'github',
            'url' => $url2 = 'git@github.com:account/repository.git',
            'installationSource' => 'source'
        ];
        $crawler = $client->request('GET', '/admin/edit/' . md5("$type: $url"));
        $form = $crawler->filterXPath('//form');
        $client->submit($form->form(), ['repository' => $params]);

        $response = $client->getResponse();
        $this->assertEquals(302, $response->getStatusCode());

        /** @var vfsStreamFile $configHandle */
        $configHandle = $this->vfsRoot->getChild('satis.json');
        $config = json_decode($configHandle->getContent());
        $this->assertEquals($url2, $config->repositories[0]->url);
        $this->assertEquals('github', $config->repositories[0]->type);
        $this->assertEquals('source', $config->repositories[0]->{'installation-source'});

        // remove repository
        $crawler = $client->request('GET', '/admin/delete/' . md5("$type2: $url2"));
        $form = $crawler->filterXPath('//form');
        $client->submit($form->form());
        $response = $client->getResponse();
        $this->assertEquals(302, $response->getStatusCode());
    }
}
