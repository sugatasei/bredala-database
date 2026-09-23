# bredala-database — bugs, pièges et améliorations

État au 2026-09-23, après relecture du code, des tests, du README et du skill, puis
correction des sept bugs relevés.

## 1. État de la suite de tests : verte

```
Tests: 306, Assertions: 457
OK
```

53 de ces tests sont nouveaux : `PdoDbSqliteTest`, `QbSqliteTest` et
`SessionHandlerSqliteTest` exécutent le SQL généré contre un vrai moteur, là où le reste
de la suite se contente d'assertions sur la chaîne produite. Base commune dans
`tests/Support/SqliteTestCase.php`, chargée par `tests/bootstrap.php`.

La dérive code/tests/doc qui restait sur `FB` est résorbée :

- **`checkIdentifier()`** — [src/FB.php:418](src/FB.php#L418) était passé de
  `preg_match("^[a-zA-Z0-9]+$", …)` à `preg_match("/^[a-zA-Z0-9]+$/", …)` sans que le
  test ni la doc suivent. Test retourné en `testCheckIdentifierAcceptsAlnumAndReturnsItUnchanged`
  + `testCheckIdentifierRejectsAnythingElse` (5 cas de rejet). Deux réserves épinglées
  au passage : la chaîne vide traverse la garde de truthiness, et **la méthode n'est
  jamais appelée en interne** — elle ne protège rien toute seule.
- **`addFk()` / `$update`** — [src/FB.php:375](src/FB.php#L375) émet désormais
  `" ON UPDATE {$update}"`. Test retourné en `testAddFkHonoursItsUpdateArgument`.
- **`addFk()` / validation no-op** — corrigé ici :
  [src/FB.php:368](src/FB.php#L368) passe de `Exception::prepare($message);` à
  `throw Exception::prepare($message);`. Le test qui épinglait l'`Undefined array key 1`
  devient `testAddFkRejectsATargetThatIsNotTableDotColumn` (3 cas) + un test vérifiant
  qu'un rejet ne laisse **rien** dans le constructeur.

Doc resynchronisée dans `README.md`, `SKILL.md`, `gotchas.md` et `api-reference.md`.

- [x] ~~Mettre à jour le `todo.md` racine~~ — fait.

## 2. Bugs corrigés

Les sept sont traités. Chacun est épinglé par des tests, et la doc (`README.md`,
`SKILL.md`, `gotchas.md`, `api-reference.md`) a été resynchronisée à chaque fois.

### ~~`OR`/`AND` sans parenthèses~~ — **corrigé**

`_where()` / `_having()` ne concatènent plus une chaîne plate : `QB` tient une **pile
de groupes** (`where_stack`, `having_stack`), chacun portant l'unique opérateur qui lie
ses membres. Mélanger `AND` et `OR` dans un même groupe lève désormais
`Exception::build()` (code `Exception::BUILD = 6`) au lieu d'émettre du SQL valide à la
sémantique inversée. La sortie est **inchangée octet pour octet** pour tout groupe
homogène, donc aucun test existant n'a bougé.

Corrigés au passage, même cause racine (`group_count` était un compteur unique, non
empilé) :

- **Groupe vide** : `groupStart()->groupEnd()->where(…)` émettait `AND ( )` puis perdait
  l'opérateur de la condition suivante → SQL rejeté. Un groupe vide est maintenant
  supprimé, et ne fixe pas l'opérateur de son parent.
- **`HAVING` n'avait aucune échappatoire** : même bug de priorité, mais sans
  `havingGroupStart()`. Les trois méthodes `havingGroupStart()` / `orHavingGroupStart()` /
  `havingGroupEnd()` ont été ajoutées.

Limite connue : l'erreur levée en **fermant** un groupe (`_groupEnd`) remonte au terminal
(`read()`, `count()`…) et non au `where()` fautif — conséquence directe du fait qu'un
groupe vide doit pouvoir disparaître sans engager d'opérateur.

Couverture : 12 tests ajoutés dans `tests/QBTest.php`. Doc resynchronisée
(`README.md`, `skills/…/SKILL.md`, `skills/…/references/gotchas.md`).

### ~~`SQLiteBuilder::add()` oublie la virgule~~ — **corrigé**

[src/SQLiteBuilder.php:72](src/SQLiteBuilder.php#L72) : `implode(",\n\t", $this->fk)`
au lieu de `implode("\n\t", …)`. Les deux tests qui épinglaient le bug deviennent
`testConstraintLinesAreCommaSeparated` et `testTwoForeignKeysAreCommaSeparated`, plus
`testTheGeneratedCreateTableIsAcceptedBySQLite` qui exécute réellement le `CREATE TABLE`
(PK + FK) sur un `PDO` SQLite en mémoire — le seul test du paquet qui vérifie que le SQL
généré est accepté par un moteur.

### ~~`FB::quote()` retourne `''` sur falsy~~ — **corrigé**

[src/FB.php:481](src/FB.php#L481) : la garde en truthiness devient un test explicite
sur `null`. `quote(0)` et `quote('0')` rendent `'0'`, `quote('')` rend `''`,
`quote(false)` rend `'0'` et `quote(true)` `'1'`. **`null` reste le seul cas rendant une
chaîne vide** — les appelants lisent ça comme « aucun littéral, omets la clause », et
c'est sur quoi repose la garde `$this->default !== null` de `Column`.

`Column::defaultValue('0')` et `defaultValue('')` émettent donc maintenant
`DEFAULT '0'` et `DEFAULT ''`.

### ~~`Column::defaultValue(false)` → `DEFAULT` nu~~ — **corrigé**

Chemin distinct du précédent, découvert en corrigeant `quote()` : un `bool` ne prend pas
la branche chaîne, il n'atteignait donc jamais `quote()`, et l'interpolation de `false`
rendait `""`. [src/Column.php:207](src/Column.php#L207) convertit désormais tout `bool`
en `1`/`0` avant l'aiguillage — même convention que `Column::bool()`, ce qu'un test
vérifie explicitement (`testABooleanDefaultMatchesWhatBoolEmits`).

Reste documenté comme piège, pas corrigé : `defaultValue(null)` n'émet **aucun**
`DEFAULT` tout en appliquant `notNull()`, la garde de rendu étant `$default !== null`.

### ~~`whereEq($col, [])` → `IS NULL`~~ — **corrigé**

Le tableau vide partageait la branche de `null` dans `_whereAuto()`. Une garde placée en
tête ([src/QB.php:271](src/QB.php#L271)) émet maintenant `1 = 0` pour `whereEq()` et
`1 = 1` pour `whereNot()` : appartenir à l'ensemble vide est faux pour toute ligne.

Arbitrage retenu — **condition constante**, pas d'exception. Le mélange `OR`/`AND` est une
faute de call-site, visible en lisant le code ; un tableau vide est une **donnée
d'exécution**. Lever aurait imposé un `if (!$ids)` à chaque appelant pour un cas qui a une
réponse définie, et l'erreur serait tombée en production, pas en test.

`IN ()` n'était pas utilisable : SQLite l'accepte (extension propriétaire), MySQL le
rejette. Vérifié.

À connaître : **le cas vide n'est pas la limite continue du cas non vide.**
`whereNot($col, [])` retourne les lignes où `$col IS NULL`, alors que
`whereNot($col, [1])` les exclut — `NULL NOT IN (1)` vaut `NULL`, pas `true`. Logique
ternaire de SQL, pas un défaut du correctif ; épinglé par
`testTheEmptyListIsNotTheLimitOfTheNonEmptyOne`.

`whereEq()` accepte toujours un scalaire, `null`, un tableau **et** un `Query` — quatre
sémantiques sous un nom qui en annonce une. Les alias explicites `whereIn()` /
`whereNotIn()` / `orWhereIn()` / `orWhereNotIn()` ont été ajoutés depuis (§4) ; ils sont
typés `array|Query`, donc un scalaire ou `null` y lève une `TypeError`.

### ~~`PDO\DB::one()` ne rend une ligne que si `rowCount() === 1`~~ — **corrigé**

`rowCount()` n'est garanti par PDO que pour les instructions qui modifient des lignes ;
sur un `SELECT`, SQLite rend 0. `one()` retournait donc **toujours `null` sous SQLite**,
y compris pour `SessionHandler::read()` qui rendait systématiquement `''` — aucune
session jamais restaurée.

[src/PDO/DB.php:320](src/PDO/DB.php#L320) conserve le contrat documenté (« exactement une
ligne ») mais le vérifie en **sondant une seconde ligne** au lieu de lire `rowCount()`.
Comportement inchangé sous MySQL, fonctionnel sous SQLite.

## 2 bis. Limites constatées en écrivant les tests SQLite

Aucune des deux n'est corrigée — ce sont des changements de comportement à arbitrer.

**1. `count()` est inutilisable après un `SELECT`.** Même cause : c'est `rowCount()`.
SQLite rend 0 là où MySQL rend le nombre de lignes. Épinglé par
`testCountIsNotUsableAfterASelect`. Le corriger imposerait de bufferiser le jeu de
résultats, ce qui change le profil mémoire de la classe. Documenté à la place :
compter en SQL via `QB::count()`.

**2. Tous les paramètres sont liés en `PDO::PARAM_STR`.** `execute()` passe le tableau
entier à `PDOStatement::execute()`, qui lie tout en chaîne. Sans conséquence sous MySQL.
Sous SQLite, la comparaison fonctionne face à une **colonne** — son affinité convertit le
texte — mais **pas** face à une expression sans affinité : `having('COUNT(*) > ?', 1)`
compare un entier à `'1'`, or un entier précède toujours le texte dans l'ordre de tri
SQLite, donc la clause ne retient rien. Épinglé par
`testABoundIntegerComparedToAnExpressionMatchesNothingUnderSqlite`.

- [ ] **Arbitrer le binding typé** — remplacer `execute($data)` par une boucle
      `bindValue()` détectant `int`/`bool`/`null`. Corrige silencieusement du SQL
      aujourd'hui faux sous SQLite, mais touche le chemin d'exécution de **toutes** les
      requêtes, MySQL compris.

## 3. Pièges de conception (pas des bugs, mais documentés comme tels)

- Terminaux `QB` **sans argument** : `->read($table)` est silencieusement ignoré
  (c'était exactement le bug `SessionHandler` corrigé).
- Aucun constructeur ne se réinitialise entre terminaux. Un constructeur réutilisé
  accumule ses clauses : en créer un par instruction.
- `addRaw()`, `addListRaw()`, `increment()`, `decrement()` : valeur **inlinée sans
  placeholder** → injection SQL directe si entrée utilisateur.
- Identifiants jamais validés ni échappés (tables, colonnes, alias, joins).
  `FB::checkIdentifier()` existe mais n'est appelé nulle part en interne.
- `Query::__toString()` : substitution sans échappement, debug uniquement.
- `count()` alias le résultat en **`sum`** ([src/QB.php:577](src/QB.php#L577)), pas `count`.
- `limit(0)` = pas de limite, pas « zéro ligne ».
- `Column::defaultValue(null)` applique `notNull()` mais n'émet aucun `DEFAULT`
  (garde `$default !== null`). Aucune API ne donne « nullable avec valeur par défaut ».
- `addIndex()` prend une map `colonne => bool`, pas une liste — `['a']` indexe une
  colonne nommée `0`.
- `Exception::*` sont des **fabriques**, pas des lanceurs. C'était la source du bug de
  validation d'`addFk()` — le motif reste un piège partout ailleurs.
- Cosmétique : `DELETE  FROM` double espace ([src/QB.php:855](src/QB.php#L855)),
  `SQLiteBuilder::del()` sans point-virgule final, index SQLite sans préfixe de table
  (collisions), `defaultTimestamp(true)` double espace.

## 4. Améliorations proposées

- [x] ~~**Groupage `OR`/`AND`**~~ — arbitrage tranché : **lever**, plutôt que
      parenthéser automatiquement. Auto-parenthéser remplace une sémantique implicite par
      une autre et laisse le call-site tout aussi illisible ; pour un bug dont le symptôme
      est « résultat faux sans signal », la correction doit produire un signal.
- [x] ~~**Couvrir `PDO\DB` et `PDO\Factory`**~~ — fait, via SQLite en mémoire.
- [ ] **Couvrir `Doc\*`** — toujours zéro test. `Doc\Database` exige l'`information_schema`
      de MySQL, donc SQLite ne suffira pas : il faut soit un serveur, soit un double.
- [x] ~~**Fournir `whereIn()` / `whereNotIn()` explicites**~~ — plus `orWhereIn()` et
      `orWhereNotIn()`. Même SQL que `whereEq()` avec un tableau, mais typés
      `array|Query` : un scalaire ou `null` lève une `TypeError` au lieu de basculer
      silencieusement sur `= ?` ou `IS NULL`.
- [ ] **Garde sur `createTable()` sans colonne** (produit un corps vide rejeté par MySQL).
- [x] ~~**Aligner `QB`/`FB`** sur une même politique de reset~~ — la politique est
      supprimée côté `FB`. `query()` ne réinitialise plus, `createSchema()` non plus, et
      `reset()` passe en `protected` : c'est l'initialiseur du constructeur, pas de l'API.
      Les trois constructeurs (`QB`, `FB`, `SQLiteBuilder`) gardent désormais leur état ;
      on en crée un par instruction.
- [ ] **Re-synchroniser `gotchas.md`, `README.md` et le `todo.md` racine** après chaque
      correction — la dérive `checkIdentifier` montre que rien ne le garantit aujourd'hui.

## 5. État du dépôt

Aucun commit n'existe pour ce travail :

- modifiés : `README.md`, `composer.json`, `src/Column.php`, `src/Exception.php`,
  `src/FB.php`, `src/PDO/DB.php`, `src/QB.php`, `src/SQLiteBuilder.php`,
  `src/SessionHandler.php`
- non suivis : `tests/`, `skills/`, `phpunit.xml`, `.gitignore`

`.gitignore` couvre `tests/tmp/` et `*.sqlite` / `*.sqlite3` / `*.db` : les tests
d'intégration travaillent en mémoire, sauf celui qui vérifie qu'une session survit à la
connexion qui l'a écrite, lequel a besoin d'un fichier (supprimé en `tearDown()`).
