# jee4lm
La Marzocco plugin for jeedom

Cette version de plugin fonctionne avec une Linea Mini et la balance supplémentaire (Brew By Weight). Elle doit marcher également avec une Micra et une GS3, par contre tous les éléments de la GS3 ne sont pas pris en compte sur cette version plus orientée vers les machines plus accessibles.

Elle permet de sélectionner et suivre les paramètres principaux, les api sont prêtes pour faire de la configuration, mais l'idée est plus de pouvoir l'utiliser au quotidien, c'est à dire :

- afficher tous les paramètres de configuration et les rafraichir toutes les heures
- rafraichir toutes les minutes les choses importantes
- allumer et éteindre les éléments (café, vapeur)
- suvire la température de montée du groupe et de la vapeur
- sélectionner les 2 entrées et changer le poids à mesurer en extraction (rien, A, B)

Les réglages couvrent également les temps de prétrempage, tous les paramètres relatifs au Brew By Weight, à savoir les éléments sur la balance, notamment la batterie pour pouvoir mettre une alerte, les 2 doses qu'on peut modifier et sélectionner celle que l'on souhaite.

Avec ça il y a de quoi faire des thermostats sur Homebridge / Alexa pour les bouilloires et quelques switches pour régler rapidement la poids à extraire, la température ciblée et allumer/éteindre.

Cette version n'utilise que les API sur le site web et pas la communication directe avec la machine qui est plus complexe à mettre en oeuvre, notamment à travers bluetooth (il faut un démon).

Il existe des api locales également, mais il faut une clé spécifique pour l'utiliser qui n'est pas simple à obtenir. les api web suffisent car elles couvrent le volant fonctionnel utile.

les éléments créés utilisent les types génériques pour faciliter le report sur les assistants vocaux et homekit. 

pour utiliser le plugin c'est facile

1) installer le plugin
2) sur l'écran de configuration du plugin, activez-le, puis 
3) cliquer pour vous connecter et obtenir un crédentiel API via la fenêtre qui s'ouvre. pour ça il faut le compte et le mot de passe utilisé sur le site La Marzocco. 
4) une fois que la connexion a réussie, lancer une détection. elle va créer les objets et ajouter les commandes de base.
5) après ça toutes les minutes les éléments principaux sont rafraichis et le reste n'est rafraichi que de temps à autre pour ne pas saturer les API et entrer en collision avec l'application sur le smartphone.

source api from HA
https://github.com/zweckj/pylamarzocco/tree/main


- 
