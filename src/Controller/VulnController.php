<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class VulnController extends AbstractController
{
    #[Route('/vuln/{id}', name: 'app_vuln_detail')]
    public function detail(int $id): Response
    {
        // Données statiques de maquette — à remplacer par les vraies données plus tard
        $vulns = [
            1 => [
                'package'  => 'symfony/http-kernel',
                'version'  => '>=5.4.0, <5.4.47',
                'cve'      => 'CVE-2024-50341',
                'title'    => 'La version 5.4.0 du composant symfony/http-kernel expose une vulnérabilité d\'injection via la gestion des paramètres de requête HTTP non validés. Un attaquant peut injecter des valeurs malveillantes dans les headers si l\'input n\'est pas assaini avant traitement. Mise à jour vers la version 5.4.47 ou supérieure recommandée immédiatement.',
                'severity' => 'critical',
                'url'      => 'https://symfony.com/cve-2024-50341',
                'fix'      => 'composer require symfony/http-kernel:^5.4.47',
                'tool'     => 'composer_audit',
            ],
            2 => [
                'package'  => 'doctrine/orm',
                'version'  => '>=2.14.0, <2.14.5',
                'cve'      => 'CVE-2024-42352',
                'title'    => 'Faille d\'injection SQL dans doctrine/orm permettant à un attaquant de manipuler des requêtes via des paramètres non échappés.',
                'severity' => 'high',
                'url'      => 'https://github.com/advisories/GHSA-xxxx',
                'fix'      => 'composer require doctrine/orm:^2.14.5',
                'tool'     => 'composer_audit',
            ],
            3 => [
                'package'  => 'src/Controller/UserController.php',
                'version'  => 'ligne 42',
                'cve'      => null,
                'title'    => 'Injection de paramètre non validé directement dans une requête SQL construite manuellement.',
                'severity' => 'high',
                'url'      => null,
                'fix'      => null,
                'tool'     => 'semgrep',
            ],
            4 => [
                'package'  => 'lodash',
                'version'  => '<4.17.21',
                'cve'      => 'CVE-2021-23337',
                'title'    => 'Prototype pollution dans lodash permettant d\'écraser des propriétés d\'objets JS.',
                'severity' => 'high',
                'url'      => 'https://nvd.nist.gov/vuln/detail/CVE-2021-23337',
                'fix'      => 'npm install lodash@^4.17.21',
                'tool'     => 'npm',
            ],
            5 => [
                'package'  => 'twig/twig',
                'version'  => '>=3.7.0, <3.8.0',
                'cve'      => 'CVE-2024-51754',
                'title'    => 'Faille XSS dans twig/twig via les templates non filtrés.',
                'severity' => 'medium',
                'url'      => 'https://github.com/advisories/GHSA-yyyy',
                'fix'      => 'composer require twig/twig:^3.8.0',
                'tool'     => 'composer_audit',
            ],
        ];

        if (!isset($vulns[$id])) {
            throw $this->createNotFoundException('Vulnérabilité introuvable.');
        }

        return $this->render('vuln/detail.html.twig', [
            'vuln' => $vulns[$id],
        ]);
    }
}
