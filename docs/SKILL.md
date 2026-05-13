---
name: grocy-daily-meal-plan
description: Generate two realistic daily recipes, lunch and dinner, from a user's Grocy stock while meeting nutrition targets. Use when the user asks for meal plans, lunch/dinner suggestions, recipes, macro planning, expiring-food usage, or Grocy-based nutrition planning. The default goals are max 1900 kcal, max 60 g fat, max 200 g carbs, min 30 g fiber, and min 160 g protein, unless the user gives different goals.
---

# Grocy Daily Meal Plan

## Goal

Create two human-realistic recipes for one day: lunch and dinner. Use Grocy stock, prioritize food near expiration and fresh food, and meet the nutrition goals as closely as possible.

Default daily goals:
- Max `1900 kcal`
- Max `60 g fat`
- Max `200 g carbs`
- Min `30 g fiber`
- Min `130 g protein`

Use user-specified targets when provided. Treat relative dates in the user's timezone and state the concrete date being planned.

## Data Source

Use the Grocy API when environment variables are available:
- `GROCY_BASE_URL`
- `GROCY_API_KEY`
- `GROCY_USER`
- `GROCY_PASSWORD`

Use HTTP Basic auth from `GROCY_USER:GROCY_PASSWORD` plus the `GROCY-API-KEY` header.

Fetch:
- `/api/stock` for current stock, aggregated amounts, and best-before dates.
- `/api/objects/products` for product records and product `userfields`.
- `/api/objects/quantity_units` for unit labels.
- `/api/objects/product_barcodes` when nutrition repair or OpenFoodFacts lookup is needed.

Nutrition userfields on products:
- `kcal100g`
- `fat`
- `carbs`
- `fiber`
- `protein`

Assume nutrition values are per `100 g` or `100 ml`. If stock units are packages or pieces, avoid pretending exact package weights are known unless Grocy has a conversion or barcode amount that makes it clear.

## Workflow

1. Read stock and product nutrition from Grocy.
2. Build a candidate list of in-stock products with nutrition fields.
3. Sort candidates by earliest `best_before_date`, with fresh foods and due-soon items first.
4. Identify protein boosters:
   - Protein powders/shakes.
   - High-protein puddings, skyr/quark/yogurt, tofu, seitan, vegan chunks, vegan hack, veggie schnitzel, protein bars.
5. Compose lunch and dinner as real recipes, not just macro piles.
6. Use protein shakes in `30 g` servings only when food-based recipes do not reach protein goals cleanly.
7. Calculate nutrition per meal from Grocy userfields and chosen gram/ml amounts.
8. Return meal names, ingredients with amounts, short cooking instructions, per-meal nutrition, and daily totals versus goals.

## Realistic Recipe Rules

Prefer meals someone would actually cook and eat:
- Use a coherent format: wrap, pasta, bowl, curry, stir-fry, salad plate, soup, tray bake, sandwich, or dessert.
- Keep sauces and toppings physically plausible, e.g. `50-80 g` yogurt sauce in a wrap, not `200 g`.
- Avoid combining unrelated protein sources just to hit numbers unless the result is a recognizable dish.
- Put protein shakes on the side instead of inside savory meals unless requested.
- Limit a single main protein portion to a normal range when possible:
  - Vegan chunks/tofu/hack: about `150-250 g`
  - Falafel: about `100-180 g`
  - Protein powder: `30 g` serving
  - Yogurt/skyr/quark: about `150-300 g`, usually as bowl, sauce, or dessert
- Prefer one main carb per meal: pasta, wrap, rice, bread, potatoes, or similar.
- Use vegetables/fresh food naturally as sides, fillings, or sauce bases.

If exact goals conflict with realistic eating, prioritize realistic meals and explain the tradeoff briefly.

## Nutrition Repair

When a product is useful but one or more nutrition fields are empty:
1. Check whether Grocy has a barcode for the product.
2. Query Grocy's external lookup endpoint first:
   `/api/stock/barcodes/external-lookup/{barcode}`
3. If that fails or returns incomplete data, query OpenFoodFacts directly by barcode.
4. If no exact match has nutrition but the user explicitly allows generic values or the item is a plain produce/staple item, use a similar product or generic food value.
5. Populate only missing nutrition userfields that are clear enough.
6. Report when generic/substituted nutrition values were used.

Do not overwrite existing non-empty nutrition values unless the user explicitly asks.

## Planning Heuristics

Scoring should favor:
- Earlier expiration dates.
- Fresh produce and products due soon.
- High protein per calorie when protein is short.
- Lower fat when near the fat cap.
- Normal meal structure and ingredient compatibility.

Do not try to maximize carbs just because the carb limit is high. It is a maximum, not a target.

## Output Format

Keep the answer practical and concise:

```text
Plan for YYYY-MM-DD

Lunch: <recipe name>
- Ingredients
- Method
- Nutrition: kcal, protein, carbs, fat, fiber

Dinner: <recipe name>
- Ingredients
- Method
- Nutrition: kcal, protein, carbs, fat, fiber

Daily total:
- kcal: used / goal
- protein: used / goal
- carbs: used / goal
- fat: used / goal
- fiber: used / goal

Notes:
- Expiring-soon items used
- Protein shake servings used
- Any generic nutrition substitutions
```

Use grams/ml for nutrition calculations. If the user asks for portions by package or piece, include the approximate gram basis used.
