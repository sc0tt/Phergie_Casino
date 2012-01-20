SET statement_timeout = 0;
SET client_encoding = 'UTF8';
SET standard_conforming_strings = on;
SET check_function_bodies = false;
SET client_min_messages = warning;
SET search_path = public, pg_catalog;
SET default_tablespace = '';
SET default_with_oids = false;

CREATE TABLE jackpotlog (
    id integer NOT NULL,
    nick text NOT NULL,
    game text NOT NULL,
    cash integer NOT NULL,
    date timestamp without time zone NOT NULL
);

CREATE SEQUENCE jackpotlog_nick_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;

ALTER SEQUENCE jackpotlog_nick_seq OWNED BY jackpotlog.nick;

CREATE TABLE settings (
    id integer DEFAULT 0 NOT NULL,
    jackpot integer DEFAULT 0
);

CREATE SEQUENCE userlist_id
    START WITH 0
    INCREMENT BY 1
    MINVALUE 0
    NO MAXVALUE
    CACHE 1;

CREATE TABLE userlist (
    id integer DEFAULT nextval('userlist_id'::regclass) NOT NULL,
    nick text NOT NULL,
    joindate text NOT NULL,
    cash integer DEFAULT 0 NOT NULL,
    debt integer DEFAULT 0 NOT NULL
);

ALTER TABLE jackpotlog ALTER COLUMN id SET DEFAULT nextval('jackpotlog_nick_seq'::regclass);

ALTER TABLE ONLY jackpotlog
    ADD CONSTRAINT jackpotlog_pkey PRIMARY KEY (id);

ALTER TABLE ONLY settings
    ADD CONSTRAINT settings_pkey PRIMARY KEY (id);

ALTER TABLE ONLY userlist
    ADD CONSTRAINT "userlist_Nick_key" UNIQUE (nick);

ALTER TABLE ONLY userlist
    ADD CONSTRAINT userlist_pkey PRIMARY KEY (id);

REVOKE ALL ON SCHEMA public FROM PUBLIC;
REVOKE ALL ON SCHEMA public FROM postgres;
GRANT ALL ON SCHEMA public TO postgres;
GRANT ALL ON SCHEMA public TO PUBLIC;

### Edit <user> with your dedicated database user for this bot ###
ALTER TABLE public.jackpotlog_nick_seq OWNER TO <user>;
ALTER TABLE public.settings OWNER TO <user>;
ALTER TABLE public.userlist_id OWNER TO <user>;
ALTER TABLE public.jackpotlog OWNER TO <user>;