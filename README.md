# Bredala/Database

Boîte à outils SQL bas niveau pour PHP : deux constructeurs de chaînes (`QB` pour les requètes, `FB` pour le DDL), une fine enveloppe PDO, un gestionnaire de sessions et un utilitaire d'introspection de schéma.

Ce n'est pas un ORM : pas de modèles, pas de relations, pas d'exécuteur de migrations, pas de chargement paresseux. On obtient une chaîne SQL et ses paramètres liés, et on l'exécute soi-même.

## Installation

```bash
composer require sugatasei/bredala-database
```

## Claude Code

Ce package fournit un skill Claude Code dans [`skills/bredala-database/`](skills/bredala-database/) qui documente les patterns d'usage et les pièges de la librairie (mélange `OR`/`AND` refusé hors groupe, tableau vide qui ne correspond à rien, aucun constructeur qui se réinitialise entre terminaux, `FB::checkIdentifier()` jamais appelé en interne, etc.).

Dans un projet qui dépend de `sugatasei/bredala-database`, copie-le une fois dans `.claude/skills/` après `composer install` pour que Claude Code le charge automatiquement (le nom du dossier doit correspondre au `name` déclaré dans `SKILL.md`) :

```bash
cp -r vendor/sugatasei/bredala-database/skills/bredala-database .claude/skills/bredala-database
```

## QB

`Bredala\Database\QB` Constructeur de requètes. Chaque méthode de clause retourne `QB`, chaque **terminal** retourne un `Query`.

### Construction

- `__construct(string $table = '')`
- `create(string $table = ''): QB` Équivalent statique.

### Sélection

- `select(string ...$cols): QB` **Cumule** les appels au lieu de remplacer.
- `distinct(): QB`

### Jointures

- `join(string $table, string $cond): QB`
- `left(string $table, string $cond): QB`
- `right(string $table, string $cond): QB`

### Conditions

- `where(string $statement, ...$values): QB` Condition brute avec ses paramètres liés.
- `orWhere(string $statement, ...$values): QB`
- `whereEq(string $field, $value): QB`
- `whereNot(string $field, $value): QB`
- `orWhereEq(string $field, $value): QB`
- `orWhereNot(string $field, $value): QB`
- `whereIn(string $field, array|Query $values): QB` / `whereNotIn(...)` / `orWhereIn(...)` / `orWhereNotIn(...)`
- `groupStart(): QB` / `orGroupStart(): QB` / `groupEnd(): QB`

`whereEq()` et `whereNot()` s'adaptent au type de la valeur :

| Valeur | SQL produit |
| ------ | ----------- |
| scalaire | `field = ?` / `field <> ?` |
| `null` | `field IS NULL` / `field IS NOT NULL` |
| tableau non vide | `field IN (?,?,…)` / `field NOT IN (…)` |
| `[]` | `1 = 0` / `1 = 1` — ne correspond à rien / à tout |
| un `Query` | `field IN (<sous-requète>)`, paramètres fusionnés |

**`whereIn()` est l'écriture explicite** de `whereEq()` avec un ensemble, et produit exactement le même SQL. `whereEq()` porte quatre sémantiques sous un nom qui en annonce une ; quand la valeur est un ensemble, le dire au call-site enlève l'ambiguïté. La famille n'accepte **qu'**un tableau ou un `Query` — un scalaire ou `null` lève une `TypeError`.

**Le tableau vide ne correspond à rien**, et son miroir `whereNot()` correspond à tout : appartenir à l'ensemble vide est faux pour toute ligne. Un filtre construit depuis une liste d'identifiants vide rend donc zéro ligne, sans qu'il faille traiter le cas en amont.

La condition émise est `1 = 0` / `1 = 1` et non `IN ()`, qui n'est pas portable — SQLite l'accepte, MySQL le rejette.

**Le cas vide n'est pas la limite continue du cas non vide.** `whereNot('role_id', [])` retourne toutes les lignes, y compris celles où `role_id IS NULL` ; `whereNot('role_id', [1])` exclut ces lignes, `NULL NOT IN (1)` valant `NULL` et non `true`. C'est la logique ternaire de SQL, pas une particularité du constructeur.

**Un `OR` exige toujours un groupe.** Un groupe de conditions est lié par **un seul** opérateur : la deuxième condition le fixe, les suivantes doivent l'employer. Mélanger `AND` et `OR` dans un même groupe lève une `Exception` de code `Exception::BUILD` plutôt que d'émettre du SQL à plat — `a AND b OR c` serait valide mais signifierait `(a AND b) OR c`, `AND` liant plus fort que `OR` en SQL. Pour exprimer un mélange, il faut grouper explicitement :

```php
QB::create('users')
    ->whereEq('active', 1)
    ->groupStart()
        ->whereEq('role', 'admin')
        ->orWhereEq('role', 'owner')
    ->groupEnd()
    ->read();
// WHERE active = ? AND (role = ? OR role = ?)
```

Le groupe porte `OR` en interne et se rattache au parent par `AND` : deux opérateurs différents, mais jamais dans le même groupe. Sans lui, la chaîne lève.

Détails du comportement des groupes :

- Un groupe **vide** est supprimé : `groupStart()->groupEnd()` ne produit rien et ne fixe pas l'opérateur du parent. Un groupe dont le contenu est conditionnel est donc sûr.
- Les groupes **s'imbriquent** sans limite, chaque niveau ayant son propre opérateur.
- Un `groupEnd()` oublié est **fermé par le terminal** (`read()`, `count()`, …).
- Un `groupEnd()` sans `groupStart()` correspondant est sans effet.
- Le contrôle porte aussi sur `HAVING`, avec ses propres `havingGroupStart()` / `orHavingGroupStart()` / `havingGroupEnd()`.

### Regroupement et tri

- `groupBy(string ...$cols): QB`
- `having(string $statement, ...$values): QB` / `orHaving(string $statement, ...$values): QB`
- `havingGroupStart(): QB` / `orHavingGroupStart(): QB` / `havingGroupEnd(): QB` Mêmes règles que les groupes du `WHERE`.
- `orderAsc(string ...$cols): QB` / `orderDesc(string ...$cols): QB` / `orderBy(string ...$cols): QB` Cumulent dans l'ordre d'appel.
- `limit(int $limit, int $offset = 0): QB` Une limite de `0` est omise, ce qui signifie « pas de limite ».

### Données à écrire

- `add(string $col, $value): QB` Valeur **liée** par un paramètre.
- `addRaw(string $col, $value): QB` Valeur **insérée telle quelle** dans le SQL.
- `addList(array $data): QB` / `addListRaw(array $data): QB`
- `increment(string $col, $val = 1): QB` / `decrement(string $col, $val = 1): QB` Passent par `addRaw()`.

`addRaw()`, `addListRaw()`, `increment()` et `decrement()` **insèrent leur valeur dans le SQL** sans paramètre lié ni échappement. Elles existent pour des expressions comme `NOW()` ou `col + 1` : leur passer une donnée utilisateur est une injection SQL directe.

Les arguments de `limit()` sont également insérés, mais leur typage `int` rend cela sûr.

**Aucun identifiant n'est validé ni échappé** : noms de tables, de colonnes, alias, conditions de jointure et instructions brutes passées à `where()`/`having()` sont recopiés verbatim. Seules les *valeurs* sont liées. Ne jamais construire un identifiant depuis un paramètre de requète.

### Terminaux

Tous retournent un `Query` et ne prennent **aucun nom de table** : la table vient de `QB::create()`.

- `read(): Query`
- `count(): Query` Alias le résultat en `sum`, pas `count`.
- `insert(bool $ignore = false): Query`
- `replace(): Query`
- `update(bool $ignore = false): Query`
- `delete(): Query`
- `insertAll(array $data, bool $ignore = false): Query`
- `replaceAll(array $data): Query`

Le constructeur **n'est pas réinitialisé** par un terminal : `read()` puis `count()` sur la même instance fonctionnent et partagent les conditions accumulées. C'est pratique pour une requète paginée, mais cela signifie qu'un constructeur réutilisé accumule les clauses — en créer un par requète.

`insertAll()` et `replaceAll()` utilisent une mise en forme compacte, différente de celle d'`insert()` et `replace()`.

## Query

`Bredala\Database\Query` Une chaîne SQL et ses valeurs liées. Implémente `Bredala\Database\QueryInterface`, qui déclare `getStatement(): string` et `getData(): array`.

- `__construct(string $statement = '', array $data = [])`
- `setStatement(string $statement = ''): Query` Rogne et normalise à exactement un `;` final.
- `setData(array $data = []): Query`
- `getStatement(): string`
- `getData(): array`
- `__toString()` Rendu de débogage.

`__toString()` substitue chaque valeur au `?` suivant, en entourant les chaînes de guillemets simples **sans échappement** : `"O'Brien"` devient `'O'Brien'`, du SQL invalide. À n'utiliser que pour déboguer, jamais pour exécuter.

## FB

`Bredala\Database\FB` Constructeur DDL MySQL. Les méthodes de préparation retournent `$this`, les terminaux retournent une **chaîne SQL**.

**Le constructeur n'est pas réinitialisé par un terminal**, même politique que `QB` : un terminal lit l'état préparé, il ne le consomme pas. Un `FB` réutilisé accumule donc ses clauses — en créer un par instruction.

### Construction

- `__construct()` / `create()`

### Terminaux

- `createSchema(string $name, string $charset = 'utf8mb4', string $collate = 'utf8mb4_general_ci'): string`
- `dropSchema(string $name): string` Émet `DROP DATABASE`.
- `createTable(string $name, string $comment = ""): string` Toujours `IF NOT EXISTS`.
- `dropTable(string $name): string`
- `renameTable(string $from, string $to): string`
- `alterTable(string $name): string` Applique les changements préparés.

`$name` accepte la forme `schema.table`, les deux parties étant entourées d'accents graves.

**Un terminal ne réinitialise pas l'état préparé** : il le lit sans le consommer, donc l'appeler deux fois d'affilée rend deux fois la même instruction. Un `FB` réutilisé accumule ses clauses — en créer un par instruction, comme pour `QB`.

### Colonnes

- `addColumn(string $name, ?callable $callback = null)` Le callback reçoit un `Column`.
- `dropColumn(string $name)`
- `changeColumn(string $name, ?string $new_name = null, ?callable $callback = null)`

Dans un `ALTER TABLE`, **l'ordre d'émission est fixé par le constructeur** (les suppressions avant les ajouts), pas par l'ordre d'appel.

### Clés

- `addPrimary(...$names)` / `dropPrimary()`
- `addIndex(string $name, array $cols = [])`
- `addUnique(string $name, array $cols = [])`
- `addFulltext(string $name, array $cols = [])`
- `dropIndex(string $name)` / `dropUnique(string $name)` / `dropFulltext(string $name)`
- `addFk(string $field, string $target, $delete = 'CASCADE', $update = 'CASCADE')` `$target` est de la forme `table.colonne`.
- `dropFk(string $field)`
- `addFkIndex(string $field)` / `dropFkIndex(string $field)`

`$cols` est une **map `colonne => estCroissant`**, pas une liste. `addIndex('name')` est le cas courant et porte sur la colonne du même nom ; `addIndex('combo', ['a' => true, 'b' => false])` donne `` (`a` ASC,`b` DESC) ``. Passer une liste comme `['a']` fait des *clés* du tableau les noms de colonnes : on indexe silencieusement une colonne nommée `0`.

Les noms de clés générés sont `` `{table}_{name}_{idx|unq|txt|fk}` ``. Passer le même `$name` court à l'ajout et à la suppression, pas le nom généré.

**La cible d'`addFk()` doit être de la forme `table.colonne`** : toute autre valeur (`'roles'`, `''`, `'db.roles.id'`) lève une `Exception` de code `Exception::PREPARE`, et le constructeur reste intact — aucune contrainte n'est enregistrée.

### Statiques

- `quote($value): string` Entoure de guillemets simples.
- `checkIdentifier(string $value): string`

`quote()` guillemette **toute valeur, falsy comprise** — `quote(0)` et `quote('0')` donnent `'0'`, `quote('')` donne `''`, `quote(false)` donne `'0'` et `quote(true)` `'1'` — en doublant les apostrophes internes. Seul `null` rend une chaîne vide, au sens « aucun littéral à émettre » : c'est ce qui permet aux appelants de décider s'ils écrivent la clause.

`checkIdentifier()` accepte les identifiants **strictement alphanumériques** et les retourne inchangés ; tout le reste (`_`, `.`, backtick, espace, point-virgule) lève une `Exception` de code `Exception::PREPARE`. Deux réserves : la chaîne vide passe la garde de truthiness et ressort telle quelle, et la méthode n'est **jamais appelée en interne** — `FB` interpole toujours tables, colonnes et index verbatim. C'est un outil à appeler soi-même en amont, pas une protection automatique.

## Column

`Bredala\Database\Column` Définition de colonne, construite dans un callback d'`FB::addColumn()` et rendue par `__toString()`. Par défaut : `` `nom` VARCHAR(255) NULL DEFAULT NULL ``. Toutes les méthodes sont fluides.

### Types

- `type(string $type, ...$constraints)`
- `int($prefix = '')` Le préfixe donne `BIGINT`, `TINYINT`, etc.
- `float()`
- `decimal(int $precision = 10, int $scale = 2)`
- `char(int $len)` / `varchar(int $len = 255)`
- `text($prefix = '')` / `blob($prefix = '')`
- `timestamp()` / `datetime()` / `date()` / `time()`
- `bool(bool $default = null)`

`bool()` n'est **pas** une simple déclaration de type : elle émet `TINYINT(1) UNSIGNED NOT NULL DEFAULT 0`, donc non signé, non nul et une valeur par défaut d'un seul coup.

### Modificateurs

- `unsigned(bool $value = true)`
- `notNull()`
- `defaultValue($value, bool $quote = true)` Implique `notNull()`.
- `defaultTimestamp($on_update = false)`
- `autoIncrement(bool $value = true)` Implique `unsigned()->notNull()`.
- `comment(string $value)`
- `first()` / `after(string $name)`

`defaultValue()` stocke le **littéral SQL à émettre**. Un `bool` est donc converti en `1`/`0` en amont, comme le fait `bool()` : `defaultValue(false)` donne `DEFAULT 0`. Une chaîne est guillemetée via `FB::quote()` sauf si `$quote` vaut `false`, ce qui permet les expressions (`defaultValue('CURRENT_TIMESTAMP', false)`).

En revanche `defaultValue(null)` n'émet **aucun** `DEFAULT` tout en appliquant `notNull()` — la garde de rendu est `$default !== null`. Pour une colonne nullable, ne pas appeler `defaultValue()` du tout.

`defaultValue()` impliquant `notNull()` et `autoIncrement()` impliquant `unsigned()->notNull()`, on ne peut pas obtenir par cette API une colonne nullable avec une valeur par défaut.

**L'ordre de sortie est fixe**, indépendant de l'ordre d'appel : nom, type, `UNSIGNED`, `NOT NULL`/`NULL DEFAULT NULL`, `AUTO_INCREMENT` ou `DEFAULT x`, `COMMENT`, `FIRST`/`AFTER`. `first()` l'emporte toujours sur `after()`. Redéclarer le type le remplace silencieusement.

## SQLiteBuilder

`Bredala\Database\SQLiteBuilder` DDL SQLite minimal, indépendant de `FB`.

- `__construct(string $name)` / `create(string $name): static`
- `col(string $name, string $type, string $opt = ''): static`
- `pk(string ...$columns)`
- `fk(string $column, string $dest_table, string $dest_column): static`
- `add(): string` / `rename(string $name): string` / `del(): string` / `index(string $column): string` / `unique(string $column): string`

Les contraintes issues de `pk()` et `fk()` sont séparées par des virgules, comme les colonnes : une table avec une clé primaire **et** une clé étrangère, ou deux clés étrangères, produit du SQL accepté par SQLite. Rien n'empêche par ailleurs de déclarer les contraintes dans l'argument `$opt` de `col()` :

```php
SQLiteBuilder::create('t')->col('id', 'INTEGER', 'PRIMARY KEY')->col('n', 'TEXT')->add();
```

`del()` n'a pas de `;` final, contrairement aux autres terminaux. Les noms d'index ne portent pas de préfixe de table (`idx_{colonne}`, `unq_{colonne}`) alors que les noms d'index sont globaux à la base dans SQLite. L'état **n'est pas** réinitialisé par un terminal, contrairement à `FB`.

## DBInterface et PDO\DB

`Bredala\Database\PDO\DB` implémente `Bredala\Database\DBInterface`. Constantes `HOOK_BEFORE_QUERY` et `HOOK_AFTER_QUERY`.

- `__construct(\PDO $pdo)` / `create(\PDO $pdo)`
- `exec(QueryInterface $query)` Exécute un `Query` construit.
- `query(string $sql)` / `prepare(string $statement)` / `bind(...$params)` / `execute(array $data = [])`
- `one()` / `all()` / `next()` / `count()` Récupération. **Aucune ne prend d'argument.**
- `getId(): int` Dernier identifiant inséré.
- `transaction()` / `commit()` / `rollback()`
- `use(string $database)`
- `escape(string $str): string`
- `disableFkCheck()` / `enableFkCheck()` Syntaxe MySQL (`SET FOREIGN_KEY_CHECKS`), rejetée par SQLite.
- `addHook(string $hook, callable $callback)` / `execHook(string $hook, array $params = [])`

`exec()` retourne le pilote, pas les lignes : il faut chaîner une récupération. Appeler `exec()` seul exécute l'instruction et jette le jeu de résultats.

**`one()` rend la ligne s'il y en a exactement une**, `null` sinon — zéro ligne comme deux lignes et plus. Pour « la première ligne d'un ensemble », ajouter un `limit(1)` explicite. La vérification ne peut pas passer par `rowCount()`, que PDO ne garantit que pour les instructions qui modifient des lignes ; elle sonde une seconde ligne.

**`count()` est `PDOStatement::rowCount()`** : il n'a de sens qu'après un `INSERT`, `UPDATE`, `DELETE` ou `REPLACE`. Sur un `SELECT`, sa valeur dépend du pilote — SQLite rend `0` là où MySQL rend le nombre de lignes. Pour compter des lignes, utiliser `QB::count()` et lire la colonne `sum`.

**Les paramètres sont liés en `PDO::PARAM_STR`**, `execute($data)` passant le tableau entier à `PDOStatement::execute()`. Sous MySQL c'est sans conséquence. Sous SQLite, la comparaison fonctionne face à une **colonne** (son affinité convertit le texte) mais pas face à une **expression** sans affinité : `having('COUNT(*) > ?', 1)` compare un entier à `'1'`, or un entier précède toujours le texte dans l'ordre de tri SQLite — la clause ne retient rien. Écrire la valeur en clair (`having('COUNT(*) > 1')`) lorsqu'elle ne vient pas de l'utilisateur.

Tous les mutateurs retournent `DBInterface`, donc les appels se chaînent.

```php
$row  = $db->exec($query)->one();
$rows = $db->exec($query)->all();
$n    = $db->exec($query)->count();
```

## PDO\Factory

`Bredala\Database\PDO\Factory`

- `create(string $dsn, $username = null, $password = null, array $options = []): \PDO`

Force `ERRMODE_EXCEPTION`, `EMULATE_PREPARES = false` et `STRINGIFY_FETCHES = false` sauf surcharge — passer ses propres `$options` peut donc réactiver silencieusement les requètes préparées émulées. Une `PDOException` est enveloppée dans une `Bredala\Database\Exception` de code `CONNECT`, donc un `catch (PDOException)` autour de la connexion ne se déclenche jamais.

## SessionHandler

`Bredala\Database\SessionHandler` Implémente `\SessionHandlerInterface`.

- `__construct(DBInterface $driver, array $options = [])` Options : `table` (défaut `sessions`), `id` (`id`), `time` (`ts`), `data` (`data`).

```php
session_set_save_handler(new SessionHandler($db), true);
session_start();
```

La table est à créer soi-même : une colonne d'identifiant (`char(40)`), un entier non signé pour l'horodatage, et un `text`/`blob` pour la charge. Aucune migration n'est fournie.

`read()` retourne `''` pour une session inconnue, ce que PHP interprète comme une session vide et non comme une erreur.

## Doc

`Bredala\Database\Doc\Database` Introspection de schéma.

- `__construct(DBInterface $db, string $dbname)` / `create(DBInterface $db, string $dbname)`
- `run(): DocTable[]`

`Doc\DocTable` expose `$name`, `$comment` et `$fields` (des `DocField`). `Doc\DocField` expose `$name`, `$idx`, `$type`, `$ref`, `$value` et `$comment`.

Interroge l'`information_schema` de MySQL : non portable vers SQLite ou PostgreSQL.

## Exception

`Bredala\Database\Exception` Étend `\Exception`. Codes `CONNECT` (1), `PREPARE` (2), `BIND` (3), `EXECUTE` (4), `TRANSACTION` (5).

- `connect(string $msg, \Throwable $prev = null)`
- `prepare(string $msg, \Throwable $prev = null)`
- `bind(string $msg, \Throwable $prev = null)`
- `execute(string $msg, \Throwable $prev = null)`
- `transaction(string $msg, \Throwable $prev = null)`

Ces méthodes statiques sont des **fabriques, pas des lanceuses** : elles construisent et retournent une exception. Les appeler sans `throw` compile, s'exécute et ne fait rien — c'était précisément le défaut de la validation d'`FB::addFk()`. Toujours écrire `throw Exception::prepare($msg)`.

Le type d'échec vit dans le code de l'exception, pas dans des sous-classes : tester `getCode()`.

## Utilisation

```php
use Bredala\Database\Column;
use Bredala\Database\FB;
use Bredala\Database\PDO\DB;
use Bredala\Database\PDO\Factory;
use Bredala\Database\QB;

$db = DB::create(Factory::create(
    'mysql:host=localhost;dbname=app;charset=utf8mb4',
    'user',
    'password'
));

// Migration -- un FB par instruction
$db->query(FB::create()
    ->addColumn('id', fn(Column $c) => $c->int()->autoIncrement())
    ->addColumn('email', fn(Column $c) => $c->varchar(190)->notNull())
    ->addColumn('active', fn(Column $c) => $c->bool())
    ->addColumn('created_at', fn(Column $c) => $c->timestamp()->defaultTimestamp())
    ->addPrimary('id')
    ->createTable('users', 'Utilisateurs'));

$db->query(FB::create()->addUnique('email')->alterTable('users'));

// Écriture
$db->exec(QB::create('users')
    ->add('email', 'tom@example.test')
    ->add('active', 1)
    ->addRaw('created_at', 'NOW()')
    ->insert());

$id = $db->getId();

// Lecture
$roles = ['admin', 'owner'];

$rows = $db->exec(QB::create('users u')
    ->select('u.id', 'u.email')
    ->left('roles r', 'r.id = u.role_id')
    ->whereEq('u.active', 1)
    ->whereEq('u.deleted_at', null)
    ->groupStart()
        ->whereEq('r.name', $roles)
        ->orWhereEq('u.id', $id)
    ->groupEnd()
    ->orderAsc('u.email')
    ->limit(50)
    ->read())->all();

// Transaction
$db->transaction();

try {
    $db->exec(QB::create('users')->increment('login_count')->whereEq('id', $id)->update());
    $db->commit();
} catch (\Throwable $e) {
    $db->rollback();
    throw $e;
}
```

## Tests

```bash
composer install
vendor/bin/phpunit
```

Les tests couvrent les constructeurs de chaînes (`QB`, `FB`, `Column`, `Query`, `SQLiteBuilder`), `Exception` et `SessionHandler` (avec un pilote simulé). `PDO\DB`, `PDO\Factory` et `Doc\*` exigent une connexion réelle et ne sont pas couverts.
