<?php

declare(strict_types=1);

return [

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
