module.exports = (nginxHostPath, portWS = 3000, portHTTP = 3001) => {

	const { WebSocketServer } = require('ws')
	const http = require('http')
	const { spawn } = require('child_process')
	const fs = require('fs')
	const path = require('path')

	const hostConfigs = new Map
	const clients = new Map

	try {
		const files = fs.readdirSync(nginxHostPath)
		for (const file of files){
			const filePath = path.join(nginxHostPath, file)
			const content = fs.readFileSync(filePath, 'utf8')
			if (content.includes('include wss.conf;')){
				const hostMatch = content.match(/^\s*server_name\s+([^;]+);/m)
				const rootMatch = content.match(/^\s*root\s+([^;]+);/m)
				if (hostMatch && hostMatch[1] && rootMatch && rootMatch[1]){
					const host = hostMatch[1].split(' ')[0]
					const rootPath = rootMatch[1]
					hostConfigs.set(host, rootPath)
					console.log(`Found host: ${host} -> ${rootPath}`)
				}
			}
		}
	}
	catch (error){
		console.error(`Error loading Nginx configs from ${nginxHostPath}:`, error)
		process.exit(1)
	}

	const phlo = (host, command, args = []) => new Promise((resolve, reject) => {
		const rootPath = hostConfigs.get(host)
		if (!rootPath) return reject(new Error(`No configuration found for host: ${host}`))
		const scriptPath = path.join(rootPath, 'app.php')
		const phpProcess = spawn('/usr/bin/php', [scriptPath, command, ...args])
		phpProcess.stderr.on('data', (data) => console.error(`PHP stderr for '${command}' on ${host}:\n${data.toString()}`))
		phpProcess.on('error', (err) => reject(new Error(`Failed to start PHP script for '${command}' on ${host}: ${err.message}`)))
		phpProcess.on('close', (code) => code === 0 ? resolve() : reject(new Error(`PHP script for '${command}' on ${host} exited with code ${code}`)))
	})

	const wss = new WebSocketServer({ noServer: true })
	wss.on('connection', (ws, request, host, token) => {
		console.log(`✅ ${token} connected to ${host}`)
		if (!clients.has(host)) clients.set(host, new Map)
		const hostClients = clients.get(host)
		if (!hostClients.has(token)) hostClients.set(token, new Set)
		hostClients.get(token).add(ws)
		phlo(host, 'websocket::connect', [token]).catch(err => console.error('❌ Phlo could not handle connect:', err.message))
		ws.host = host
		ws.token = token
		ws.on('message', (message) => {
			console.log(`📩 ${token} sent data to ${host}`)
			phlo(ws.host, 'websocket::receive', [ws.token, message.toString()]).catch(err => console.error('❌ Phlo could not receive message:', err.message))
		})
		ws.on('close', () => {
			console.log(`🔌 ${token} disconnected from ${host}`)
			const hostClients = clients.get(ws.host)
			if (!hostClients) return
			const tokenClients = hostClients.get(ws.token)
			if (tokenClients){
				tokenClients.delete(ws)
				if (tokenClients.size === 0) hostClients.delete(ws.token)
			}
			if (hostClients.size === 0) clients.delete(ws.host)
			phlo(ws.host, 'websocket::close', [ws.token]).catch(err => console.error('❌ Phlo could not handle disconnect:', err.message))
		})
		ws.on('error', (error) => console.error(`💥 Client error for ${token}@${host}:`, error))
	})

	const server = http.createServer(async (req, res) => {
		if (req.url === '/message' && req.method === 'POST'){
			try {
				const body = await getJSONBody(req)
				const { host, target, data } = body
				const dataString = JSON.stringify(data)
				const hostClients = clients.get(host)
				if (!hostClients) return res.writeHead(404).end(JSON.stringify({status: 'error', message: 'Host not found'}))
				if (target === 'broadcast') for (const tokenSet of hostClients.values()) for (const clientWs of tokenSet) clientWs.send(dataString)
				else for (const token of Array.isArray(target) ? target : [target]){
					const tokenClients = hostClients.get(token)
					if (tokenClients) for (const clientWs of tokenClients) clientWs.send(dataString)
				}
				console.log(`📥 ${host} sent data to`, target)
				res.writeHead(200, {'Content-Type': 'application/json'})
				res.end(JSON.stringify({status: 'ok'}))
			}
			catch (error){
				res.writeHead(400, {'Content-Type': 'application/json'})
				res.end(JSON.stringify({status: 'error', message: error.message}))
			}
		}
		else res.writeHead(404).end()
	})
	server.on('upgrade', async (request, socket, head) => {
		try {
			const host = request.headers.host
			if (!hostConfigs.has(host)) throw new Error(`Could not find valid vhost for ${host}.`)
			const cookies = Object.fromEntries((request.headers.cookie || '').split(';').map(part => {
				const [key, ...valParts] = part.trim().split('=')
				return [key, decodeURIComponent(valParts.join('='))]
			}))
			const token = cookies['token']
			if (!token) throw new Error('Authentication cookie not found.')
			await phlo(host, 'websocket::auth', [token])
			wss.handleUpgrade(request, socket, head, (ws) => wss.emit('connection', ws, request, host, token))
		}
		catch (error) {
			console.log(`Unauthorized: ${error.message}`)
			socket.write('HTTP/1.1 401 Unauthorized\r\n\r\n')
			socket.destroy()
		}
	})

	const getJSONBody = (req) => new Promise((resolve, reject) => {
		let body = ''
		req.on('data', chunk => body += chunk.toString())
		req.on('end', () => {
			try { resolve(JSON.parse(body)) }
			catch (e){ reject(new Error('Invalid JSON body.')) }
		})
		req.on('error', err => reject(err))
	})

	server.listen(portWS, '127.0.0.1', () => console.log(`🚀 WebSocket server started on port ${portWS}`))
	const internalHttpServer = http.createServer(server.listeners('request')[0])
	internalHttpServer.listen(portHTTP, '127.0.0.1', () => console.log(`📡 Internal HTTP server started on port ${portHTTP}`))

}
