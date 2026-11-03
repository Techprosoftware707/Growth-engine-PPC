<?php
/**
 * Aggregated profile of a prospect website.
 *
 * @package PPC\LinkGrowth
 */

declare(strict_types=1);

namespace PPC\LinkGrowth\Analysis;

final class SiteProfile {

    public string $domain = '';
    public string $homepageUrl = '';
    public string $name = '';
    public string $description = '';
    public string $language = '';
    public string $country = '';
    public int $countryConfidence = 0;
    public ?PageAnalysis $home = null;
    /** @var PageAnalysis[] extra pages fetched (about/contact/submission) */
    public array $pages = [];
    /** @var array<int,array{email:string,name:string,role:string,source_url:string,confidence:int}> */
    public array $contacts = [];
    public string $contactUrl = '';
    public string $submissionUrl = '';
    /** @var array<string,mixed>|null detected submission form */
    public ?array $submissionForm = null;
    public ?float $domainAgeYears = null;
    public string $registrationDate = '';
    public string $lastContentDate = '';
    public bool $reachable = false;
    public bool $https = false;
    public string $error = '';
    public int $fetches = 0;
    /** @var string[] */
    public array $technologies = [];

    /** @return array<string,mixed> stored on the prospect */
    public function toArray(): array {
        return [
            'name'               => $this->name,
            'description'        => $this->description,
            'language'           => $this->language,
            'country'            => $this->country,
            'country_confidence' => $this->countryConfidence,
            'home'               => $this->home ? $this->home->summary() : null,
            'pages'              => array_map( static fn( PageAnalysis $p ) => $p->summary(), $this->pages ),
            'contacts_found'     => count( $this->contacts ),
            'contact_url'        => $this->contactUrl,
            'submission_url'     => $this->submissionUrl,
            'submission_form'    => $this->submissionForm ? [
                'kind'        => $this->submissionForm['kind'] ?? '',
                'action'      => $this->submissionForm['action'] ?? '',
                'method'      => $this->submissionForm['method'] ?? '',
                'has_captcha' => ! empty( $this->submissionForm['has_captcha'] ),
                'has_password' => ! empty( $this->submissionForm['has_password'] ),
                'fields'      => array_map( static fn( $f ) => [ 'name' => $f['name'], 'semantic' => $f['semantic'], 'type' => $f['type'], 'required' => $f['required'], 'label' => $f['label'], 'options' => count( $f['options'] ?? [] ) ], $this->submissionForm['fields'] ?? [] ),
            ] : null,
            'domain_age_years'   => $this->domainAgeYears,
            'registration_date'  => $this->registrationDate,
            'last_content_date'  => $this->lastContentDate,
            'reachable'          => $this->reachable,
            'https'              => $this->https,
            'technologies'       => $this->technologies,
            'fetches'            => $this->fetches,
            'error'              => $this->error,
            'profiled_at'        => gmdate( 'c' ),
        ];
    }
}
