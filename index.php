<?php
require 'config.php';

$gender_filter = $_GET['gender'] ?? null;
$min_age = isset($_GET['min_age']) ? (int)$_GET['min_age'] : null;
$max_age = isset($_GET['max_age']) ? (int)$_GET['max_age'] : null;

$edit_mode = isset($_GET['edit']);
$cat_to_edit = null;
$fathers = [];

if ($edit_mode) {
    $cat_to_edit = getCat($_GET['edit']);
    $fathers = getFathers($_GET['edit']);
}

if (isset($_POST['action'])) {
    switch ($_POST['action']) {
        case 'add':
            addCat($_POST);
            break;
        case 'edit':
            editCat($_POST);
            break;
        case 'delete':
            deleteCat($_POST['id']);
            break;
    }
    header("Location: " . $_SERVER['PHP_SELF']);
    exit();
}

function getCats($gender = null, $min_age = null, $max_age = null)
{
    global $pdo;

    $sql = "SELECT c.*, 
                   m.name as mother_name,
                   GROUP_CONCAT(f.name SEPARATOR ', ') as fathers_names
            FROM cats c
            LEFT JOIN cats m ON c.mother_id = m.id
            LEFT JOIN fathers ft ON c.id = ft.kitten_id
            LEFT JOIN cats f ON ft.father_id = f.id";

    $where = [];
    $params = [];

    if ($gender) {
        $where[] = "c.gender = ?";
        $params[] = $gender;
    }

    if ($min_age !== null) {
        $where[] = "c.age >= ?";
        $params[] = $min_age;
    }

    if ($max_age !== null) {
        $where[] = "c.age <= ?";
        $params[] = $max_age;
    }

    if (!empty($where)) {
        $sql .= " WHERE " . implode(" AND ", $where);
    }

    $sql .= " GROUP BY c.id";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function addCat($data)
{
    global $pdo;

    $stmt = $pdo->prepare("INSERT INTO cats (name, gender, age, mother_id) VALUES (?, ?, ?, ?)");
    $stmt->execute([
        $data['name'],
        $data['gender'],
        $data['age'],
        $data['mother_id'] ?: null
    ]);

    $kitten_id = $pdo->lastInsertId();

    if (!empty($data['fathers']) && is_array($data['fathers'])) {
        $stmt = $pdo->prepare("INSERT INTO fathers (kitten_id, father_id) VALUES (?, ?)");
        foreach ($data['fathers'] as $father_id) {
            $stmt->execute([$kitten_id, $father_id]);
        }
    }
}

function editCat($data)
{
    global $pdo;

    $stmt = $pdo->prepare("UPDATE cats SET name = ?, gender = ?, age = ?, mother_id = ? WHERE id = ?");
    $stmt->execute([
        $data['name'],
        $data['gender'],
        $data['age'],
        $data['mother_id'] ?: null,
        $data['id']
    ]);

    $pdo->prepare("DELETE FROM fathers WHERE kitten_id = ?")->execute([$data['id']]);

    if (!empty($data['fathers']) && is_array($data['fathers'])) {
        $stmt = $pdo->prepare("INSERT INTO fathers (kitten_id, father_id) VALUES (?, ?)");
        foreach ($data['fathers'] as $father_id) {
            $stmt->execute([$data['id'], $father_id]);
        }
    }
}

function deleteCat($id)
{
    global $pdo;
    $pdo->prepare("DELETE FROM cats WHERE id = ?")->execute([$id]);
}

function getPotentialMothers()
{
    global $pdo;
    return $pdo->query("SELECT id, name FROM cats WHERE gender = 'female'")->fetchAll();
}

function getPotentialFathers()
{
    global $pdo;
    return $pdo->query("SELECT id, name FROM cats WHERE gender = 'male'")->fetchAll();
}

function getCat($id)
{
    global $pdo;
    $stmt = $pdo->prepare("SELECT * FROM cats WHERE id = ?");
    $stmt->execute([$id]);
    return $stmt->fetch();
}

function getFathers($kitten_id)
{
    global $pdo;
    $stmt = $pdo->prepare("SELECT father_id FROM fathers WHERE kitten_id = ?");
    $stmt->execute([$kitten_id]);
    return $stmt->fetchAll(PDO::FETCH_COLUMN, 0);
}

$cats = getCats($gender_filter, $min_age, $max_age);
?>

<!DOCTYPE html>
<html lang="ru">

<head>
    <meta charset="UTF-8">
    <title>Учёт кошек</title>
    <link rel="stylesheet" href="styles.css">
</head>

<body>
    <div class="container">
        <h1>Учёт кошек</h1>

        <div class="form-container">
            <h2><?= $edit_mode ? 'Редактировать кошку' : 'Добавить новую кошку' ?></h2>
            <form method="post">
                <input type="hidden" name="action" value="<?= $edit_mode ? 'edit' : 'add' ?>">
                <?php if ($edit_mode): ?>
                    <input type="hidden" name="id" value="<?= $cat_to_edit['id'] ?>">
                <?php endif; ?>

                <div class="form-row">
                    <div class="form-group">
                        <label for="name">Кличка:</label>
                        <input type="text" id="name" name="name" required value="<?= htmlspecialchars($cat_to_edit['name'] ?? '') ?>">
                    </div>

                    <div class="form-group">
                        <label for="gender">Пол:</label>
                        <select id="gender" name="gender" required>
                            <option value="male" <?= ($cat_to_edit['gender'] ?? '') == 'male' ? 'selected' : '' ?>>Кот</option>
                            <option value="female" <?= ($cat_to_edit['gender'] ?? '') == 'female' ? 'selected' : '' ?>>Кошка</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="age">Возраст (лет):</label>
                        <input type="number" id="age" name="age" min="0" required value="<?= $cat_to_edit['age'] ?? '' ?>">
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="mother_id">Мать (если это котенок):</label>
                        <select id="mother_id" name="mother_id">
                            <option value="">Не указана</option>
                            <?php foreach (getPotentialMothers() as $mother): ?>
                                <option value="<?= $mother['id'] ?>" <?= ($cat_to_edit['mother_id'] ?? '') == $mother['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($mother['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <?php if (!$edit_mode || ($cat_to_edit && $cat_to_edit['mother_id'])): ?>
                    <div class="form-group">
                        <label>Возможные отцы (если это котенок):</label>
                        <div class="checkbox-group">
                            <?php foreach (getPotentialFathers() as $father): ?>
                                <div class="checkbox-item">
                                    <input type="checkbox" id="father_<?= $father['id'] ?>" name="fathers[]" value="<?= $father['id'] ?>"
                                        <?= in_array($father['id'], $fathers) ? 'checked' : '' ?>>
                                    <label for="father_<?= $father['id'] ?>"><?= htmlspecialchars($father['name']) ?></label>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <div class="action-buttons">
                    <button type="submit" class="btn btn-success"><?= $edit_mode ? 'Сохранить' : 'Добавить' ?></button>
                    <?php if ($edit_mode): ?>
                        <a href="?" class="btn btn-secondary">Отмена</a>
                    <?php endif; ?>
                </div>
            </form>
        </div>

        <form method="get" class="filter-form">
            <div class="filter-group">
                <label for="gender">Пол:</label>
                <select id="gender" name="gender">
                    <option value="">Все</option>
                    <option value="male" <?= ($gender_filter ?? '') == 'male' ? 'selected' : '' ?>>Коты</option>
                    <option value="female" <?= ($gender_filter ?? '') == 'female' ? 'selected' : '' ?>>Кошки</option>
                </select>
            </div>

            <div class="filter-group">
                <label for="min_age">Возраст от:</label>
                <input type="number" id="min_age" name="min_age" min="0" value="<?= $min_age ?? '' ?>">
            </div>

            <div class="filter-group">
                <label for="max_age">до:</label>
                <input type="number" id="max_age" name="max_age" min="0" value="<?= $max_age ?? '' ?>">
            </div>

            <button type="submit" class="btn">Фильтровать</button>
            <a href="?" class="btn btn-secondary">Сбросить</a>
        </form>

        <h2>Список кошек</h2>
        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Кличка</th>
                        <th>Пол</th>
                        <th>Возраст</th>
                        <th>Мать</th>
                        <th>Отцы</th>
                        <th>Действия</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($cats as $cat): ?>
                        <tr>
                            <td><?= $cat['id'] ?></td>
                            <td><?= htmlspecialchars($cat['name']) ?></td>
                            <td><?= $cat['gender'] == 'male' ? 'Кот' : 'Кошка' ?></td>
                            <td><?= $cat['age'] ?></td>
                            <td><?= htmlspecialchars($cat['mother_name'] ?? '') ?></td>
                            <td><?= htmlspecialchars($cat['fathers_names'] ?? '') ?></td>
                            <td>
                                <a href="?edit=<?= $cat['id'] ?>" class="btn btn-sm">Редактировать</a>
                                <form method="post" style="display: inline;">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?= $cat['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-danger">Удалить</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</body>

</html>