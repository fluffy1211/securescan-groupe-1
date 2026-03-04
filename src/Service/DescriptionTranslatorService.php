<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Traduit les descriptions de vulnérabilités (Semgrep / npm / composer) vers le français.
 *
 * La traduction se fait en deux passes :
 *  1. Par check_id (règle Semgrep) : plus précis, utilisé en priorité.
 *  2. Par mots-clés dans la description anglaise : filet de sécurité pour les règles inconnues.
 */
class DescriptionTranslatorService
{
    /**
     * Traduit une description en français.
     *
     * @param string|null $description  Texte anglais original
     * @param string|null $checkId      Identifiant de la règle Semgrep (ex: "php.lang.security.injection")
     */
    public function translate(?string $description, ?string $checkId = null): ?string
    {
        if ($description === null) {
            return null;
        }

        if ($checkId !== null) {
            $fromId = $this->translateByCheckId($checkId);
            if ($fromId !== null) {
                return $fromId;
            }
        }

        return $this->translateByKeywords($description);
    }

    private function translateByCheckId(string $checkId): ?string
    {
        $id = strtolower($checkId);

        $map = [
            'detected-generic-secret'        => 'Secret générique détecté en clair dans le code source (clé API, token, mot de passe). Ne jamais stocker de secrets directement dans le code ou les fichiers de configuration versionnés.',
            'hardcoded-password'             => 'Mot de passe codé en dur dans le code source. Utilisez des variables d\'environnement ou un gestionnaire de secrets.',
            'hardcoded-secret'               => 'Secret codé en dur dans le code source. Utilisez des variables d\'environnement ou un gestionnaire de secrets.',
            'hardcoded-credentials'          => 'Identifiants de connexion codés en dur. Cela expose vos systèmes en cas de fuite du code source.',
            'missing-user-entrypoint'        => 'Aucun utilisateur non-root défini dans le Dockerfile. Un programme s\'exécutant en tant que root dans un conteneur représente un risque critique si un attaquant prend le contrôle du processus.',
            'missing-user'                   => 'Aucun utilisateur spécifié dans le Dockerfile. Par défaut, le conteneur s\'exécutera en tant que root, ce qui est un risque de sécurité.',
            'no-new-privileges'              => 'L\'option --security-opt=no-new-privileges n\'est pas définie. Un processus pourrait élever ses privilèges.',
            'sql-injection'                  => 'Risque d\'injection SQL : des données non validées sont utilisées dans une requête SQL. Utilisez des requêtes paramétrées.',
            'sqli'                           => 'Risque d\'injection SQL : des données non validées sont utilisées dans une requête SQL. Utilisez des requêtes paramétrées.',
            'xss'                            => 'Risque de Cross-Site Scripting (XSS) : des données utilisateur sont affichées sans échappement. Un attaquant peut injecter du code JavaScript malveillant.',
            'cross-site-scripting'           => 'Risque de Cross-Site Scripting (XSS) : des données utilisateur sont affichées sans échappement. Un attaquant peut injecter du code JavaScript malveillant.',
            'code-injection'                 => 'Risque d\'injection de code : des données non fiables sont évaluées comme du code. Évitez l\'utilisation de eval() avec des entrées utilisateur.',
            'command-injection'              => 'Risque d\'injection de commande : des données utilisateur sont passées à un shell système. Un attaquant peut exécuter des commandes arbitraires.',
            'path-traversal'                 => 'Risque de traversée de répertoire : un attaquant peut accéder à des fichiers en dehors du répertoire autorisé.',
            'directory-traversal'            => 'Risque de traversée de répertoire : un attaquant peut accéder à des fichiers en dehors du répertoire autorisé.',
            'open-redirect'                  => 'Redirection ouverte : l\'URL de redirection n\'est pas validée, ce qui peut mener à des attaques de phishing.',
            'ssrf'                           => 'Falsification de requête côté serveur (SSRF) : le serveur peut être amené à effectuer des requêtes vers des ressources internes non autorisées.',
            'xxe'                            => 'Injection d\'entité externe XML (XXE) : le parseur XML accepte des entités externes pouvant exposer des fichiers système.',
            'weak-crypto'                    => 'Algorithme cryptographique faible détecté (MD5, SHA1, DES…). Utilisez des algorithmes modernes comme SHA-256 ou AES.',
            'md5'                            => 'Utilisation de MD5, un algorithme de hachage obsolète et vulnérable. Utilisez SHA-256 ou bcrypt selon le cas d\'usage.',
            'sha1'                           => 'Utilisation de SHA-1, un algorithme de hachage obsolète. Préférez SHA-256 ou une alternative moderne.',
            'insecure-random'                => 'Utilisation d\'un générateur de nombres aléatoires non sécurisé. Pour la cryptographie, utilisez un CSPRNG.',
            'missing-authentication'         => 'Authentification manquante : un point d\'accès est accessible sans vérification d\'identité.',
            'broken-authentication'          => 'Authentification défaillante : la vérification d\'identité peut être contournée.',
            'missing-csrf'                   => 'Protection CSRF absente : les requêtes soumises sans token peuvent être forgées par un site tiers.',
            'csrf'                           => 'Risque CSRF : absence de protection contre les requêtes intersites forgées.',
            'insecure-deserialization'       => 'Désérialisation non sécurisée : des données non fiables sont désérialisées, ce qui peut permettre l\'exécution de code arbitraire.',
            'security-misconfiguration'      => 'Mauvaise configuration de sécurité détectée. Vérifiez les paramètres de sécurité de votre application.',
            'sensitive-data-exposure'        => 'Exposition de données sensibles : des informations confidentielles peuvent être accessibles sans autorisation.',
            'broken-access-control'          => 'Contrôle d\'accès défaillant : un utilisateur peut accéder à des ressources auxquelles il ne devrait pas avoir accès.',
            'using-components-with-known'    => 'Utilisation de composants présentant des vulnérabilités connues. Mettez à jour vos dépendances.',
            'insufficient-logging'           => 'Journalisation insuffisante : les événements de sécurité ne sont pas correctement enregistrés.',
            'prototype-pollution'            => 'Pollution de prototype JavaScript : un attaquant peut modifier les propriétés des objets globaux.',
            'eval'                           => 'Utilisation de eval() avec des données potentiellement non fiables. Cela peut permettre l\'exécution de code arbitraire.',
            'exec'                           => 'Exécution d\'une commande système avec des données potentiellement non fiables. Risque d\'injection de commande.',
            'unvalidated-redirect'           => 'Redirection non validée vers une URL externe. Risque de phishing ou de vol de session.',
            'jwt'                            => 'Mauvaise configuration JWT détectée. Vérifiez la validation et la signature des tokens.',
            'cors'                           => 'Configuration CORS trop permissive. Restreignez les origines autorisées.',
            'tls'                            => 'Configuration TLS incorrecte ou désactivée. Assurez-vous d\'utiliser TLS 1.2 ou supérieur.',
            'ssl'                            => 'Configuration SSL incorrecte ou désactivée. Utilisez TLS à la place.',
        ];

        foreach ($map as $pattern => $translation) {
            if (str_contains($id, $pattern)) {
                return $translation;
            }
        }

        return null;
    }

    private function translateByKeywords(string $description): string
    {
        $lower = strtolower($description);

        $keywords = [
            'sql injection'              => 'Injection SQL détectée : des données non validées sont utilisées dans une requête SQL.',
            'cross-site scripting'       => 'Cross-Site Scripting (XSS) : données utilisateur affichées sans échappement.',
            'xss'                        => 'Risque de Cross-Site Scripting (XSS).',
            'secret'                     => 'Secret ou donnée sensible détectée en clair dans le code source.',
            'password'                   => 'Mot de passe détecté en clair dans le code source.',
            'run as root'                => 'Le conteneur s\'exécute en tant que root, ce qui est un risque de sécurité.',
            'root'                       => 'Risque lié aux privilèges root détecté.',
            'hardcoded'                  => 'Valeur sensible codée en dur dans le code source.',
            'command injection'          => 'Injection de commande : des données utilisateur sont passées à un shell système.',
            'path traversal'             => 'Traversée de répertoire : accès à des fichiers en dehors du répertoire autorisé.',
            'open redirect'              => 'Redirection ouverte non validée.',
            'ssrf'                       => 'Falsification de requête côté serveur (SSRF).',
            'eval'                       => 'Utilisation de eval() avec des données potentiellement non fiables.',
            'md5'                        => 'Algorithme MD5 obsolète et vulnérable.',
            'sha1'                       => 'Algorithme SHA-1 obsolète.',
            'weak'                       => 'Algorithme ou mécanisme de sécurité faible détecté.',
            'csrf'                       => 'Protection CSRF manquante ou insuffisante.',
            'deserialization'            => 'Désérialisation non sécurisée de données non fiables.',
            'prototype pollution'        => 'Pollution de prototype JavaScript détectée.',
            'unvalidated'                => 'Données non validées utilisées dans un contexte sensible.',
            'missing'                    => 'Contrôle de sécurité manquant.',
        ];

        foreach ($keywords as $keyword => $translation) {
            if (str_contains($lower, $keyword)) {
                return $translation;
            }
        }

        return $description;
    }
}
