-- Položka platebního příkazu je záznam pokynu pro banku a nese vlastní údaje o příjemci i částce.
-- Smazání vydané faktury ji nesmí blokovat, stejně jako u přijaté faktury, která cizí klíč nemá.
ALTER TABLE payment_order_items DROP FOREIGN KEY IF EXISTS fk_poi_invoice;
