set search_path = website;

DROP TABLE predefined_request_group_asso;
DROP TABLE predefined_request_group;
DROP TABLE predefined_request_criteria;
DROP TABLE predefined_request_result;
DROP TABLE predefined_request;

/*==============================================================*/
/* Table : PREDEFINED_REQUEST                                   */
/*==============================================================*/
create table PREDEFINED_REQUEST (
ID						SERIAL,
REQUEST_NAME             VARCHAR(50)          not null,
SCHEMA_CODE          	 VARCHAR(36)          not null,
DATASET_ID               VARCHAR(36)          not null,
DEFINITION				 VARCHAR(500)         null,
LABEL 					 VARCHAR(50)	      null,
DATE 					 date				DEFAULT now(),
USER_LOGIN 				 VARCHAR(50),
constraint PK_PREDEFINED_REQUEST primary key (ID)
);



/*alter table PREDEFINED_REQUEST*/
/*add constraint FK_PREDEFINED_REQUEST_DATASET foreign key (DATASET_ID)*/
/*      references METADATA.DATASET (DATASET_ID)*/
/*      on delete restrict on update restrict;*/

COMMENT ON COLUMN PREDEFINED_REQUEST.ID IS 'The id of the request';
COMMENT ON COLUMN PREDEFINED_REQUEST.REQUEST_NAME IS 'The request name';
COMMENT ON COLUMN PREDEFINED_REQUEST.SCHEMA_CODE IS 'The schema used by this request';
COMMENT ON COLUMN PREDEFINED_REQUEST.DATASET_ID IS 'The dataset used by this request';
COMMENT ON COLUMN PREDEFINED_REQUEST.DEFINITION IS 'The description of the request';
COMMENT ON COLUMN PREDEFINED_REQUEST.LABEL IS 'The label of the request';
COMMENT ON COLUMN PREDEFINED_REQUEST.DATE IS 'Date of creation of the request';
COMMENT ON COLUMN PREDEFINED_REQUEST.USER_LOGIN IS 'The login of the user who created the request';


-- Index: website.predefined_request_unique_request_name_user_login_not_null

-- DROP INDEX website.predefined_request_unique_request_name_user_login_not_null;

CREATE UNIQUE INDEX predefined_request_unique_request_name_user_login_not_null
  ON website.predefined_request
  USING btree
  (request_name COLLATE pg_catalog."default", user_login COLLATE pg_catalog."default")
  WHERE user_login IS NOT NULL;

-- Index: website.predefined_request_unique_request_name_user_login_null

-- DROP INDEX website.predefined_request_unique_request_name_user_login_null;

CREATE UNIQUE INDEX predefined_request_unique_request_name_user_login_null
  ON website.predefined_request
  USING btree
  (request_name COLLATE pg_catalog."default")
  WHERE user_login IS NULL;
  
/*==============================================================*/
/* Table : PREDEFINED_REQUEST_CRITERIA                          */
/*==============================================================*/
create table PREDEFINED_REQUEST_CRITERIA (
ID_PREDEFINED_REQUEST  INTEGER              not null,
FORMAT         		   VARCHAR(36)          not null,
DATA                   VARCHAR(36)          not null,
VALUE        		   VARCHAR(500)          not null,
FIXED 				   boolean,
constraint PK_PREDEFINED_REQUEST_CRITERIA primary key (ID_PREDEFINED_REQUEST, FORMAT, DATA)
);

COMMENT ON COLUMN PREDEFINED_REQUEST_CRITERIA.ID_PREDEFINED_REQUEST IS 'The id of the predefined request';
COMMENT ON COLUMN PREDEFINED_REQUEST_CRITERIA.FORMAT IS 'The form format of the criteria';
COMMENT ON COLUMN PREDEFINED_REQUEST_CRITERIA.DATA IS 'The form field of the criteria';
COMMENT ON COLUMN PREDEFINED_REQUEST_CRITERIA.VALUE IS 'The field value (multiple values are separated by a semicolon)';
COMMENT ON COLUMN PREDEFINED_REQUEST_CRITERIA.FIXED IS 'Indicate if the file is fixed or selectable';

ALTER TABLE ONLY predefined_request_criteria
    ADD CONSTRAINT fk_predefined_request_criteria_id_predefined_request
    FOREIGN KEY (id_predefined_request) 
    REFERENCES predefined_request(id) ON UPDATE RESTRICT ON DELETE CASCADE;

/*==============================================================*/
/* Table : PREDEFINED_REQUEST_RESULT                  */
/*==============================================================*/
create table PREDEFINED_REQUEST_RESULT (
ID_PREDEFINED_REQUEST  INTEGER              not null,
FORMAT         		   VARCHAR(36)          not null,
DATA                   VARCHAR(36)          not null,
constraint PK_PREDEFINED_REQUEST_RESULT primary key (ID_PREDEFINED_REQUEST, FORMAT, DATA)
);

COMMENT ON COLUMN PREDEFINED_REQUEST_RESULT.ID_PREDEFINED_REQUEST IS 'The id of the predefined request';
COMMENT ON COLUMN PREDEFINED_REQUEST_RESULT.FORMAT IS 'The form format of the result column';
COMMENT ON COLUMN PREDEFINED_REQUEST_RESULT.DATA IS 'The form field of the result column';

ALTER TABLE ONLY predefined_request_result
    ADD CONSTRAINT fk_predefined_request_result_id_predefined_request
    FOREIGN KEY (id_predefined_request) 
    REFERENCES predefined_request(id) ON UPDATE RESTRICT ON DELETE CASCADE;


-- Sequence: website.predefined_request_group_position_seq

/*DROP SEQUENCE IF EXISTS website.predefined_request_group_position_seq;

CREATE SEQUENCE website.predefined_request_group_position_seq
  INCREMENT 1
  MINVALUE 1
  MAXVALUE 9223372036854775807
  START 1
  CACHE 1;
ALTER TABLE website.predefined_request_group_position_seq
  OWNER TO admin;*/


/*==============================================================*/
/* Table : PREDEFINED_REQUEST_GROUP                             */
/*==============================================================*/
CREATE TABLE PREDEFINED_REQUEST_GROUP (
GROUP_NAME 		VARCHAR(50) 	NOT NULL,
LABEL			VARCHAR(50),
DEFINITION  	VARCHAR(250),
/*POSITION		integer NOT NULL DEFAULT nextval('predefined_request_group_position_seq'::regclass),*/
POSITION		smallint,
constraint PK_PREDEFINED_REQUEST_GROUP primary key (GROUP_NAME)
);

COMMENT ON COLUMN PREDEFINED_REQUEST_GROUP.GROUP_NAME IS 'The name of the group';
COMMENT ON COLUMN PREDEFINED_REQUEST_GROUP.LABEL IS 'The label of the group';
COMMENT ON COLUMN PREDEFINED_REQUEST_GROUP.DEFINITION IS 'The definition of the group';
COMMENT ON COLUMN PREDEFINED_REQUEST_GROUP.POSITION IS 'The position of the group';



/*==============================================================*/
/* Table : PREDEFINED_REQUEST_GROUP_ASSO                        */
/*==============================================================*/
CREATE TABLE PREDEFINED_REQUEST_GROUP_ASSO (
GROUP_NAME 		VARCHAR(50) 	NOT NULL,
ID_PREDEFINED_REQUEST  INTEGER  not null,
POSITION		smallint,
constraint PK_PREDEFINED_REQUEST_GROUP_ASSO primary key (GROUP_NAME, ID_PREDEFINED_REQUEST)
);

COMMENT ON COLUMN PREDEFINED_REQUEST_GROUP_ASSO.GROUP_NAME IS 'The name of the group';
COMMENT ON COLUMN PREDEFINED_REQUEST_GROUP_ASSO.ID_PREDEFINED_REQUEST IS 'The id of the predefined request';
COMMENT ON COLUMN PREDEFINED_REQUEST_GROUP_ASSO.POSITION IS 'The position of the request inside the group';


ALTER TABLE ONLY predefined_request_group_asso
    ADD CONSTRAINT fk_predefined_request_group_name 
    FOREIGN KEY (group_name) 
    REFERENCES predefined_request_group(group_name) ON UPDATE RESTRICT ON DELETE RESTRICT;

ALTER TABLE ONLY predefined_request_group_asso
    ADD CONSTRAINT fk_predefined_request_id_predefined_request
    FOREIGN KEY (id_predefined_request) 
    REFERENCES predefined_request(id) ON UPDATE RESTRICT ON DELETE CASCADE;


GRANT ALL ON SCHEMA website TO ogam WITH GRANT OPTION;
GRANT ALL ON ALL SEQUENCES IN SCHEMA website TO ogam;
GRANT ALL ON ALL TABLES IN SCHEMA website TO ogam;

