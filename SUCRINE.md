# Sucrine — Notes de référence Castaneas

## Objectif

Centraliser tout ce qui a été acté pour l'intégration Sucrine de Castaneas :

- configuration
- flux réel de commande
- mapping produits
- livraison
- comportement en cas d'erreur
- différences entre ancien plan et implémentation actuelle

Ce document décrit l'état actuel du projet, pas les premières pistes explorées au début du chantier.

## État actuel du flux

Le flux en place aujourd'hui est le suivant :

1. Le client valide son panier sur Castaneas.
2. `checkout.php?action=create` crée une commande locale avec statut `pending_payment`.
3. Le paiement est envoyé vers Up2pay / Crédit Agricole.
4. `payment-return.php` ne suffit plus à marquer la commande comme payée.
5. La vraie confirmation de paiement passe par `payment-notify.php`.
6. Quand la commande passe en `paid`, `payment-flow.php` déclenche l'envoi vers Sucrine via `sucrine.php`.

Conclusion importante :

- Sucrine n'est plus appelé depuis le front.
- Sucrine n'est plus déclenché depuis `confirmation.html`.
- L'envoi vers Sucrine se fait côté serveur, après paiement confirmé.

## Fichiers impliqués

- `sucrine.php` : construction et envoi de la commande Sucrine
- `payment-flow.php` : déclenchement de l'envoi Sucrine quand la commande est payée
- `checkout.php` : persistance de la commande, shipping, promo, données client
- `integrations.php` : configuration générale
- `integrations.local.php` : configuration locale éventuelle
- `order-store.php` : persistance des commandes

## Base URL et environnements

### Production

Base URL API Sucrine de production :

- `https://app.sucrine.club/api`

### Preview / test

Base URL preview :

- `https://preview.app.sucrine.club/api`

Cette base preview a été utilisée pour les tests et pour aligner le payload sur la documentation réelle.

## Configuration attendue

Configuration type dans `integrations.php` / `integrations.local.php` / config serveur :

```php
'sucrine' => [
  'api_key' => 'VOTRE_CLE_API_SUCRINE',
  'base_url' => 'https://app.sucrine.club/api',
  'catalogue_id' => 'ID_DU_CATALOGUE_SUCRINE',
  'order_source' => 'castaneas',
  'skip_precise_supply_check' => true,
  'ca_bundle' => '',
  'skip_ssl_verify' => false,
  'delivery_point' => 'ID_SUCRINE_MODE_DISTRIBUTION_PAR_DEFAUT',
],
```

### Champs clés

- `api_key` : clé API Sucrine
- `base_url` : prod ou preview
- `catalogue_id` : catalogue à utiliser pour résoudre les produits
- `order_source` : source métier envoyée à Sucrine
- `delivery_point` : mode de distribution par défaut si aucun mapping plus précis n'est trouvé
- `ca_bundle` : bundle CA local si le poste Windows a un problème de certificat
- `skip_ssl_verify` : contournement local uniquement, pas recommandé en prod

## Problème SSL rencontré en local

Sur l'environnement Windows local, les appels HTTPS vers certaines APIs peuvent échouer avec une erreur de certificat du type :

- `unable to get local issuer certificate`

Pour Sucrine, deux options ont été prévues côté runtime :

- `sucrine.ca_bundle`
- `sucrine.skip_ssl_verify`

Usage recommandé :

- utiliser `ca_bundle` si possible
- garder `skip_ssl_verify` pour le local uniquement
- ne pas activer `skip_ssl_verify` en production

## Endpoints Sucrine utilisés

### Création de commande

- `POST /professional/customerOrders/order`

### Catalogue

- `GET /professional/catalogues/{catalogueId}`

### Delivery points

- `GET /professional/catalogues/{catalogueId}/deliveryPoints`

### Contacts

- `POST /professional/contacts`
- `GET /professional/contacts`

## Mapping produits : référence Castaneas -> référence Sucrine

### Règle métier retenue

Le client Castaneas continue à saisir des références de type `AR...` dans le back-office.

Exemple :

- `AR00107`

Mais Sucrine n'accepte pas directement ce SKU visible dans le payload final de commande. Il faut donc résoudre cette valeur vers la référence interne attendue par Sucrine.

### Comportement implémenté

Dans Castaneas, le champ produit `sucrineId` peut contenir :

- soit un SKU `AR...`
- soit directement un `catalogueItemPriceId` Sucrine

Si la valeur commence par `AR`, `sucrine.php` :

1. charge le catalogue via `GET /professional/catalogues/{catalogueId}`
2. parcourt `standardCatalogue[*].rawPrices[*]`
3. cherche une correspondance via :
   - `rawPrices[].sku`
   - `rawPrices[].price.sku`
   - `rawPrices[].metadata.woocommerceIdentifier`
4. récupère le vrai identifiant interne `rawPrices[]._id`
5. utilise cet identifiant dans la commande envoyée à Sucrine

### Résultat fonctionnel

Le marchand saisit toujours les références `AR...` dans Castaneas, mais au moment d'envoyer la commande à Sucrine, Castaneas traduit automatiquement ce SKU en identifiant Sucrine interne.

C'était l'exigence principale du chantier produit côté Sucrine.

## Payload commande Sucrine

Le payload a été réaligné pour coller à la documentation Sucrine observée pendant les tests preview.

Les points importants retenus :

- l'adresse doit être structurée comme attendu par l'API
- le `deliveryPoint` doit être valide
- les items doivent utiliser les bons identifiants résolus
- des champs horaires / créneaux ont été ajoutés quand nécessaires
- le prix unitaire envoyé doit être cohérent avec ce qu'attend Sucrine

### Champs spécifiques intégrés côté Castaneas

Selon le flux actuellement en place, la construction du payload prend en compte notamment :

- `advancedCatalogueItems` / structure d'items attendue par Sucrine
- `deliveryPoint`
- `ePrice`
- `customTimeSlot`
- `timeSlot`
- `timeSlotEnd`
- adresses de livraison / facturation
- données contact
- message / informations liées au relais si nécessaire

## Livraison et points de distribution

### Règle générale

Sucrine exige un `deliveryPoint` valide dans la commande.

### État du projet

Le système prend désormais en charge une résolution plus fine du `deliveryPoint` Sucrine selon les données de livraison persistées au checkout.

La configuration peut inclure un mapping `sucrine.delivery_points` basé sur des identifiants de méthode d'expédition ou d'option logistique, par exemple :

- `shipping.code`
- `product.code`
- combinaison de type `carrier:type`
- autres identifiants persistés dans la commande

### Cas point relais

L'intégration a été rendue relais-aware :

- si la commande utilise un point relais, le payload Sucrine tient compte des informations de relais
- les champs message / adresse peuvent être adaptés pour conserver l'information utile côté Sucrine

## Sendcloud et Sucrine

Le flux shipping côté Castaneas a été renforcé pour persister les bonnes métadonnées avant l'envoi vers Sucrine et avant la génération de label.

Points actés :

- `checkout.php` persiste les informations de méthode de livraison
- les métadonnées incluent notamment `product.code` et `selectedFunctionalities`
- `sendcloud.php` peut ensuite résoudre le bon `shipment.id`
- les anciennes commandes payées sans métadonnées de livraison suffisantes peuvent échouer explicitement lors de la génération de label

Cette partie est liée à Sucrine parce que les décisions de livraison et les mappings de distribution doivent rester cohérents entre les deux systèmes.

## Gestion des contacts existants

### Problème rencontré

Sucrine peut refuser une création de commande si le contact existe déjà, avec un retour de type :

- `ContactExistingError`

### Comportement implémenté

Si Sucrine retourne cette erreur, Castaneas :

1. récupère l'identifiant du contact existant retourné par Sucrine
2. reconstruit la requête
3. renvoie la commande avec `orderedBy` pointant vers ce contact existant

Résultat :

- on évite un échec définitif de la commande pour simple collision de contact
- les commandes d'un client déjà connu passent correctement

## Ce qui a changé par rapport aux premières pistes

Au début du chantier, une piste consistait à :

- appeler Sucrine depuis le front
- créer un simple proxy PHP dédié
- déclencher l'envoi depuis `confirmation.html`

Ce n'est plus l'architecture retenue.

### Architecture retenue maintenant

La version correcte et durable est :

- commande persistée côté serveur
- paiement confirmé côté serveur
- envoi Sucrine uniquement après statut `paid`

C'est plus robuste car :

- pas de dépendance au navigateur client
- pas d'envoi si le paiement n'est pas vraiment confirmé
- meilleure traçabilité des commandes
- meilleure résilience en cas de retour navigateur incomplet

## Back-office : saisie attendue

Pour chaque produit, le back-office permet de renseigner la référence Sucrine.

Règle pratique :

- saisir de préférence le SKU `AR...`
- garder la possibilité de saisir directement un `catalogueItemPriceId` brut si nécessaire

Le libellé de champ a été clarifié dans l'admin pour refléter cette règle.

## Checklist de mise en service Sucrine

1. Renseigner `sucrine.api_key`.
2. Renseigner `sucrine.base_url` selon l'environnement.
3. Renseigner `sucrine.catalogue_id`.
4. Renseigner `sucrine.delivery_point` par défaut si aucun mapping plus fin n'est prévu.
5. Vérifier que chaque produit a bien une référence `sucrineId` exploitable.
6. Préférer les SKU `AR...` dans le back-office quand ils existent.
7. Vérifier que le mapping `sucrine.delivery_points` est cohérent avec les méthodes d'expédition réellement persistées.
8. Tester une commande payée complète.
9. Vérifier que la commande devient `paid` avant envoi Sucrine.
10. Vérifier qu'un contact existant ne bloque pas la commande.

## Checklist de debug

Si une commande n'arrive pas dans Sucrine, vérifier dans cet ordre :

1. la commande locale existe bien
2. le statut de commande est bien passé à `paid`
3. `payment-notify.php` a bien été appelé
4. `sucrine.api_key` est correct
5. `sucrine.base_url` pointe vers le bon environnement
6. `sucrine.catalogue_id` est correct
7. le produit a une référence `sucrineId`
8. la référence `AR...` existe réellement dans le catalogue Sucrine
9. le `deliveryPoint` résolu est valide
10. la machine locale n'est pas bloquée par un problème SSL

## Résumé court

Les décisions les plus importantes prises sur Sucrine sont :

- envoi uniquement après paiement confirmé
- résolution automatique SKU `AR...` -> identifiant interne Sucrine
- gestion des contacts déjà existants
- prise en charge des cas relais / livraison
- configuration serveur centralisée
- contournement SSL local prévu pour Windows si nécessaire

## À garder en tête

Ce document complète `INTEGRATIONS.md`, mais sur la partie Sucrine il fait foi par rapport à l'implémentation actuelle.

Si `INTEGRATIONS.md` et ce document divergent, considérer ce fichier comme la référence opérationnelle sur Sucrine.
