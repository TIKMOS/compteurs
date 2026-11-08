<?php

declare(strict_types=1);

return [

    /*
    | Version des conditions d'utilisation (EF-51, H-36). La changer quand le texte de
    | resources/views/pages/conditions-utilisation.blade.php change : chaque propriétaire
    | devra accepter la nouvelle version à sa prochaine connexion.
    */
    'terms_version' => '2026-11-08',

    /*
    | Avertissement de conformité (EF-48, D-21). Texte affiché à l'activation du mode 2
    | et à chaque désactivation du plafond ; il est recopié tel quel dans chaque
    | confirmation enregistrée (compliance_acknowledgements.notice_text).
    | Ne pas modifier sans validation du propriétaire du projet.
    */
    'compliance_notice' => 'Les règlements de service de la SBEE (art. 90) et de la SONEB interdisent '
        . 'à l\'abonné de revendre ou céder l\'eau et l\'électricité à des tiers. '
        . 'Vous restez seul responsable du respect de vos contrats.',

];
