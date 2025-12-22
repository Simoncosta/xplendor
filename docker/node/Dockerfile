# docker/node/Dockerfile
FROM node:20-alpine

WORKDIR /app

# Copia apenas os manifests
COPY web/package*.json ./

# Instala dependências
RUN npm install

# Copia o resto
COPY web .

EXPOSE 3000

CMD ["npm", "run", "dev"]