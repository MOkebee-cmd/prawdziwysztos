-- =====================================================================
-- SKILLTREE STUDIO - SETUP SUPABASE
-- =====================================================================
-- Skopiuj zawartosc tego pliku do Supabase Dashboard:
-- Project → SQL Editor → New query → wklej → "Run"
-- =====================================================================

-- ============= TABELE =============

CREATE TABLE IF NOT EXISTS categories (
    id          TEXT PRIMARY KEY,
    name        TEXT NOT NULL,
    accent      TEXT NOT NULL DEFAULT '#7d8a78',
    type_key    TEXT NOT NULL,
    sort_order  INT  NOT NULL DEFAULT 0,
    levels      JSONB,
    last_modified_by TEXT,
    updated_at  TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS specializations (
    id            TEXT NOT NULL,
    category_id   TEXT NOT NULL REFERENCES categories(id) ON DELETE CASCADE,
    label         TEXT NOT NULL,
    description   TEXT,
    color         TEXT NOT NULL DEFAULT '#7d8a78',
    btn_color     TEXT NOT NULL DEFAULT '#7d8a7840',
    sort_order    INT  NOT NULL DEFAULT 0,
    last_modified_by TEXT,
    updated_at    TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    PRIMARY KEY (id, category_id)
);

CREATE TABLE IF NOT EXISTS perks (
    id              TEXT NOT NULL,
    spec_id         TEXT NOT NULL,
    category_id     TEXT NOT NULL,
    label           TEXT NOT NULL,
    image           TEXT,
    required_level  INT  NOT NULL DEFAULT 1,
    pos_x           INT  NOT NULL DEFAULT 200,
    pos_y           INT  NOT NULL DEFAULT 200,
    required_perks  JSONB NOT NULL DEFAULT '{}'::jsonb,
    levels          JSONB,
    last_modified_by TEXT,
    updated_at      TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    PRIMARY KEY (id, spec_id, category_id),
    FOREIGN KEY (spec_id, category_id)
        REFERENCES specializations(id, category_id)
        ON DELETE CASCADE ON UPDATE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_perks_spec ON perks (spec_id, category_id);

-- Trigger na updated_at
CREATE OR REPLACE FUNCTION skilltree_touch_updated_at()
RETURNS TRIGGER AS $$
BEGIN
    NEW.updated_at = NOW();
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trg_categories_touch ON categories;
CREATE TRIGGER trg_categories_touch BEFORE UPDATE ON categories
    FOR EACH ROW EXECUTE FUNCTION skilltree_touch_updated_at();

DROP TRIGGER IF EXISTS trg_specializations_touch ON specializations;
CREATE TRIGGER trg_specializations_touch BEFORE UPDATE ON specializations
    FOR EACH ROW EXECUTE FUNCTION skilltree_touch_updated_at();

DROP TRIGGER IF EXISTS trg_perks_touch ON perks;
CREATE TRIGGER trg_perks_touch BEFORE UPDATE ON perks
    FOR EACH ROW EXECUTE FUNCTION skilltree_touch_updated_at();

-- ============= ROW LEVEL SECURITY =============
-- Domyslnie: pelny dostep dla anon role.
-- Daje to mozliwosc edycji kazdemu kto zna URL projektu.
-- Aby ograniczyc do zalogowanych userow, zamien USING(true) na USING(auth.uid() IS NOT NULL).

ALTER TABLE categories       ENABLE ROW LEVEL SECURITY;
ALTER TABLE specializations  ENABLE ROW LEVEL SECURITY;
ALTER TABLE perks            ENABLE ROW LEVEL SECURITY;

DROP POLICY IF EXISTS "all access categories"      ON categories;
DROP POLICY IF EXISTS "all access specializations" ON specializations;
DROP POLICY IF EXISTS "all access perks"           ON perks;

CREATE POLICY "all access categories"      ON categories      FOR ALL USING (true) WITH CHECK (true);
CREATE POLICY "all access specializations" ON specializations FOR ALL USING (true) WITH CHECK (true);
CREATE POLICY "all access perks"           ON perks           FOR ALL USING (true) WITH CHECK (true);

-- ============= REALTIME =============
-- Wlaczamy publikacje dla 3 tabel zeby WebSocket dostarczal zmiany.

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_publication_tables
        WHERE pubname='supabase_realtime' AND tablename='perks'
    ) THEN
        ALTER PUBLICATION supabase_realtime ADD TABLE perks;
    END IF;
    IF NOT EXISTS (
        SELECT 1 FROM pg_publication_tables
        WHERE pubname='supabase_realtime' AND tablename='specializations'
    ) THEN
        ALTER PUBLICATION supabase_realtime ADD TABLE specializations;
    END IF;
    IF NOT EXISTS (
        SELECT 1 FROM pg_publication_tables
        WHERE pubname='supabase_realtime' AND tablename='categories'
    ) THEN
        ALTER PUBLICATION supabase_realtime ADD TABLE categories;
    END IF;
END $$;

-- ============= RPC: replace_all_data (atomowy import) =============

CREATE OR REPLACE FUNCTION replace_all_data(data jsonb, client text DEFAULT NULL)
RETURNS void
LANGUAGE plpgsql
AS $$
DECLARE
    cat_key  text;
    cat_val  jsonb;
    spec_key text;
    spec_val jsonb;
    perk_key text;
    perk_val jsonb;
BEGIN
    DELETE FROM perks;
    DELETE FROM specializations;

    FOR cat_key, cat_val IN SELECT * FROM jsonb_each(data->'types') LOOP
        UPDATE categories
        SET name   = COALESCE(cat_val->>'name', name),
            accent = COALESCE(cat_val->>'accent', accent),
            levels = COALESCE(cat_val->'levels', levels),
            last_modified_by = client
        WHERE id = cat_key;

        FOR spec_key, spec_val IN SELECT * FROM jsonb_each(cat_val->'specializations') LOOP
            INSERT INTO specializations (id, category_id, label, description, color, btn_color, last_modified_by)
            VALUES (
                spec_key, cat_key,
                COALESCE(spec_val->>'label', spec_key),
                spec_val->>'desc',
                COALESCE(spec_val->>'color', '#7d8a78'),
                COALESCE(spec_val->>'btnColor', '#7d8a7840'),
                client
            )
            ON CONFLICT (id, category_id) DO UPDATE SET
                label = EXCLUDED.label,
                description = EXCLUDED.description,
                color = EXCLUDED.color,
                btn_color = EXCLUDED.btn_color,
                last_modified_by = EXCLUDED.last_modified_by;

            FOR perk_key, perk_val IN SELECT * FROM jsonb_each(spec_val->'perks') LOOP
                INSERT INTO perks (id, spec_id, category_id, label, image, required_level, pos_x, pos_y, required_perks, levels, last_modified_by)
                VALUES (
                    perk_key, spec_key, cat_key,
                    COALESCE(perk_val->>'label', perk_key),
                    COALESCE(perk_val->>'image', ''),
                    COALESCE((perk_val->>'requiredLevel')::int, 1),
                    COALESCE((perk_val->>'x')::int, 200),
                    COALESCE((perk_val->>'y')::int, 200),
                    COALESCE(perk_val->'requiredPerks', '{}'::jsonb),
                    COALESCE(perk_val->'levels', '[{"desc":""}]'::jsonb),
                    client
                );
            END LOOP;
        END LOOP;
    END LOOP;
END;
$$;

GRANT EXECUTE ON FUNCTION replace_all_data(jsonb, text) TO anon, authenticated;

-- ============= SEED: 4 kategorie + dane demo =============

INSERT INTO categories (id, name, accent, type_key, sort_order, levels)
VALUES
    ('general', 'Ogólne',  '#7d8a78', 'character', 0,
        (SELECT jsonb_agg(jsonb_build_object('label', 'Level ' || i, 'value', 1000*i, 'perkPoints', 1))
         FROM generate_series(1, 20) AS i)),
    ('civ',     'Cywil',   '#788494', 'civ',       1,
        (SELECT jsonb_agg(jsonb_build_object('label', 'Level ' || i, 'value', 1000*i, 'perkPoints', 1))
         FROM generate_series(1, 15) AS i)),
    ('crime',   'Crime',   '#927878', 'crime',     2,
        (SELECT jsonb_agg(jsonb_build_object('label', 'Level ' || i, 'value', 1000*i, 'perkPoints', 1))
         FROM generate_series(1, 15) AS i)),
    ('faction', 'Frakcje', '#8e8270', 'trucker',   3,
        (SELECT jsonb_agg(jsonb_build_object('label', 'Level ' || i, 'value', 1000*i, 'perkPoints', 1))
         FROM generate_series(1, 5) AS i))
ON CONFLICT (id) DO NOTHING;

INSERT INTO specializations (id, category_id, label, description, color, btn_color, sort_order)
VALUES
    ('strength', 'general', 'Siła', 'Drzewko siły – cięższe ciosy, większy udźwig.', '#7d8a78', '#7d8a7840', 0),
    ('trucker',  'faction', 'Trucker', 'Specjalizacja kierowcy ciężarówki.', '#8e8270', '#8e827040', 0)
ON CONFLICT (id, category_id) DO NOTHING;

INSERT INTO perks (id, spec_id, category_id, label, image, required_level, pos_x, pos_y, required_perks, levels)
VALUES
    ('p_str_1', 'strength', 'general', 'Większa siła z ciosów', 'increase_strength_from_hits', 1, 200, 200,
        '{}'::jsonb,
        '[{"desc":"Zwiększa siłę zdobywaną z ciosów o 5%.","strengthModifier":0.05}]'::jsonb),
    ('p_str_2', 'strength', 'general', 'Większa siła z ciosów II', 'increase_strength_from_hits_2', 2, 380, 200,
        '{"p_str_1":1}'::jsonb,
        '[{"desc":"Zwiększa siłę zdobywaną z ciosów o kolejne 5%.","strengthModifier":0.05}]'::jsonb),
    ('p_inv_1', 'strength', 'general', 'Większy udźwig', 'increase_inventory_weight', 1, 200, 340,
        '{}'::jsonb,
        '[{"desc":"Zwiększa maksymalny udźwig o 10kg.","inventoryWeightModifier":10}]'::jsonb),
    ('long_distance',  'trucker', 'faction', 'Long Distance',  'long_distance',  1, 200, 200,
        '{}'::jsonb, '[{"desc":"Otrzymujesz więcej XP z długich tras."}]'::jsonb),
    ('on_time',        'trucker', 'faction', 'On Time',        'on_time',        2, 380, 200,
        '{"long_distance":1}'::jsonb, '[{"desc":"Bonus za dostarczenie na czas."}]'::jsonb),
    ('careful_driver', 'trucker', 'faction', 'Careful Driver', 'careful_driver', 3, 560, 200,
        '{"on_time":1}'::jsonb, '[{"desc":"Mniejsze zużycie pojazdu."}]'::jsonb),
    ('explosive_load', 'trucker', 'faction', 'Explosive Load', 'explosive_load', 4, 740, 200,
        '{"careful_driver":1}'::jsonb, '[{"desc":"Możliwość przewozu materiałów wybuchowych."}]'::jsonb)
ON CONFLICT (id, spec_id, category_id) DO NOTHING;

-- =====================================================================
-- KONIEC. Weryfikacja:
-- =====================================================================
SELECT 'categories' AS tbl, COUNT(*) AS n FROM categories
UNION ALL SELECT 'specializations', COUNT(*) FROM specializations
UNION ALL SELECT 'perks', COUNT(*) FROM perks;
