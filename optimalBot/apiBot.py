import web_services as WS
import chatBot_tags as CT
import chatterbot.comparisons as comp
import optimal_chatterbot.response_selection as resp
import re
from optimal_chatterbot.chatbot import chatBot as optimalbot
from optimal_chatterbot.sentence_classification import *
from optimal_chatterbot.Filters import *
from optimal_chatterbot.trainer import ListTrainerOverridden,ChatterBotCorpusTrainerOverridden
from db_manager import DBManager
from settings import *
from DataCleaning import DataCleaning


class ApiBot(WS.Rest):
    def askBot(self):
        #try:
            query = WS.Validation.validateParameter('query', self.param['query'], STRING)
            if query['valid']:
                query = query['data']
            else:
                return query['data']
            Story_ID = WS.Validation.validateParameter('story_id', self.param['story_id'], INTEGER)
            if Story_ID['valid']:
                Story_ID = Story_ID['data']
            else:
                return Story_ID['data']

            bot_name, db_server, db_name, db_username, db_password, db_driver, _, domain, db_verified, first_train = self.bot_information

            if not db_verified:
                return WS.Response.throwError(HTTP_FORBIDDEN_RESPONSE, "Sorry, Database is not verified yet.")

            if not first_train:
                return WS.Response.throwError(HTTP_FORBIDDEN_RESPONSE, "Please train the bot at least one time using our customer portal.")

            if db_driver == 'mysqli' or db_driver == 'mysql':
                # TODO: configure db_port

                uri = "mysql://" + db_username + ":" + db_password + "@" + db_server + ":3306/" + db_name
                chatbot = optimalbot(name=bot_name,
                                     storage_adapter="chatterbot.storage.SQLStorageAdapter",
                                     database_uri=uri,
                                     logic_adapters=
                                     [{
                                         "import_path": "optimal_chatterbot.FlowAdapter.FlowAdapter",
                                         "statement_comparison_function": comp.SentimentComparison,
                                         "response_selection_method": resp.get_flow_response,
                                         "maximum_similarity_threshold":0.75
                                     }],
                                     filters=[get_recent_repeated_responsesCustomized],
                                     Story_ID=Story_ID,
                                     bot_information=self.bot_information,
                                     glove = self.glove,
                                     tags = self.tags)

                # Filter User Query
                dt = DataCleaning()
                cleaned_query = dt.clean(query)
                #cleaned_query = re.sub('[^ a-zA-Z0-9]', ' ', query)
                #escaped_query = re.escape(query)
                #tokenized_query = " ".join(nltk.word_tokenize(escaped_query))
                #cleaned_query = re.sub(u"(\u2018|\u2019)", "'", tokenized_query)
                response, Story_ID, children_questions = chatbot.get_response(cleaned_query)

                return WS.Response.returnResponse(HTTP_SUCCESS_RESPONSE, {'bot_reply': str(response), 'story_id': Story_ID, 'suggested_actions': children_questions})
            else:
                return WS.Response.throwError(DATABASE_TYPE_ERROR, "Database type is not supported.")
        #except:
            #return WS.Response.throwError(JWT_PROCESSING_ERROR, "Sorry, Server is down, please contact the administrators")

    def createBot(self):
        try:
            bot_name, db_server, db_name, db_username, db_password, db_driver, _, domain, db_verified, first_train = self.bot_information
            if db_driver == 'mysqli' or db_driver == 'mysql':
                db = DBManager(user=db_username,
                            password=db_password,
                            host=db_server,
                            database=db_name)

                uri = "mysql://" + db_username + ":" + db_password + "@" + db_server + ":3306/" + db_name
                chatbot = optimalbot(name=bot_name,
                                    storage_adapter="chatterbot.storage.SQLStorageAdapter",
                                    database_uri=uri,)

                db.change_column_datatype('statement', 'text', 'text')
                db.change_column_datatype('statement', 'search_text', 'text')
                db.change_column_datatype('statement', 'in_response_to', 'text')
                db.change_column_datatype('statement', 'search_in_response_to', 'text')


                tables = [TABLE_BOT_1, TABLE_BOT_2, TABLE_BOT_3]
                for table in tables:
                    db.delete_table_data(table)

                faq_table_name = FAQ_TABLE_NAME
                Q_A = get_faq_Q_A_Pairs(faq_table_name, db)

                dt = DataCleaning()

                conversation = list()
                for key, value in Q_A.items():
                    conversation.append(dt.clean(key))
                    conversation.append(dt.clean(value))

                trainer = ChatterBotCorpusTrainerOverridden(chatbot)
                trainer.train(
                    "chatterbot.corpus.english.greetings",
                    "chatterbot.corpus.english.conversations"
                )

                trainer = ListTrainerOverridden(chatbot)
                trainer.train(conversation)

                return WS.Response.returnResponse(HTTP_SUCCESS_RESPONSE, 'success')
            else:
                return WS.Response.throwError(DATABASE_TYPE_ERROR, "Database type is not supported.")
        except:
             return WS.Response.throwError(JWT_PROCESSING_ERROR, "Sorry, Server is down, please contact the administrators")

    def checkMetaValidity(self):
        try:
            content = WS.Validation.validateParameter('content', self.param['content'], STRING)
            if content['valid']:
                content = content['data']
            else:
                return content['data']

            status = self.db.verify_meta(content)
            return WS.Response.returnResponse(HTTP_SUCCESS_RESPONSE, {'status': str(status)})
        except:
             return WS.Response.throwError(JWT_PROCESSING_ERROR, "Sorry, Server is down, please contact the administrators")

    def validateDatabase(self):
        try:
            driver = WS.Validation.validateParameter('driver', self.param['driver'], STRING)
            if driver['valid']:
                driver = driver['data']
            else:
                return driver['data']
            if driver:
                status = self.db.validate_db(self.token, driver)
                return WS.Response.returnResponse(HTTP_SUCCESS_RESPONSE, {'status': str(status)})
            else:
                return WS.Response.throwError(DATABASE_TYPE_ERROR, "Sorry, We couldn't verify your database, please check with our support")
        except:
             return WS.Response.throwError(JWT_PROCESSING_ERROR, "Sorry, Server is down, please contact the administrators")

    def suggestionTags(self):
        try:
            statement = WS.Validation.validateParameter('statement', self.param['statement'], STRING)
            if statement['valid']:
                statement = statement['data']
            else:
                return statement['data']

            similarity = CT.Similarity(self.glove,self.tags)
            statement_tags = similarity.get_tags(statement)

            return WS.Response.returnResponse(HTTP_SUCCESS_RESPONSE, {'tags':statement_tags})

        except:
             return WS.Response.throwError(JWT_PROCESSING_ERROR, "Sorry, Server is down, please contact the administrators")
